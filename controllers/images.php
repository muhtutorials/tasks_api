<?php

require_once '../DB.php';
require_once '../models/Image.php';
require_once '../models/Response.php';

function sendResponse($statusCode, $success, $message = null, $toCache = false, $data = null)
{
    $response = new Response();
    $response->setHttpStatusCode($statusCode);
    $response->setSuccess($success);
    if ($message) $response->addMessage($message);
    $response->toCache($toCache);
    if ($data) $response->setData($data);
    $response->send();
    exit;
}

function getUser($writeDB)
{
    if (!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
        $message = null;

        if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $message = 'Access token missing from the header';
        } else {
            if (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
                $message = 'Access token cannot be blank';
            }
        }

        sendResponse(401, false, $message);
    }

    $access_token = $_SERVER['HTTP_AUTHORIZATION'];

    try {
        $query = $writeDB->prepare('SELECT user_id, access_token_expiration, is_active, login_attempts FROM sessions, users WHERE sessions.user_id = users.id AND access_token = :access_token');
        $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            sendResponse(401, false, 'Invalid access token');
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_user_id = $row['user_id'];
        $returned_access_token_expiration = $row['access_token_expiration'];
        $returned_is_active = $row['is_active'];
        $returned_login_attempts = $row['login_attempts'];

        if ($returned_is_active !== 'Y') {
            sendResponse(401, false, 'User account not active');
        }

        if ($returned_login_attempts > 2) {
            sendResponse(401, false, 'User account locked');
        }

        if (strtotime($returned_access_token_expiration) < time()) {
            sendResponse(401, false, 'Access token has expired');
        }

        return $returned_user_id;
    } catch (PDOException $e) {
        error_log("Database query error - $e", 0);
        sendResponse(500, false, 'There was an issue authenticating - please try again');
    }
}

function uploadImageRoute($readDB, $writeDB, $task_id, $returned_user_id) {
    try {
        if (!isset($_SERVER['CONTENT_TYPE']) ||
            strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data; boundary=') === false
        ) {
            sendResponse(400, false, 'Content-type header not set to multipart/form-data with a boundary');
        }

        $query = $readDB->prepare('SELECT id FROM tasks WHERE id = :task_id AND user_id = :user_id');
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            sendResponse(404, false, 'Task not found');
        }

        if (!isset($_POST['attributes'])) {
            sendResponse(400, false, 'Attributes missing from body of request');
        }

        $jsonImageAttributes = json_decode($_POST['attributes']);

        if (!$jsonImageAttributes) {
            sendResponse(400, false, 'Attributes field not valid JSON');
        }

        if (!isset($jsonImageAttributes->title) || !isset($jsonImageAttributes->filename) ||
            $jsonImageAttributes->title == '' || $jsonImageAttributes->filename == ''
        ) {
            sendResponse(400, false, 'Title and filename fields are mandatory');
        }

        if (strpos($jsonImageAttributes->filename, '.') !== false) {
            sendResponse(400, false, 'Filename must not contain file extension');
        }

        if (!isset($_FILES['image_file']) || $_FILES['image_file']['error'] !== 0) {
            sendResponse(500, false, 'Image file upload unsuccessful - make sure you selected a file');
        }

        $imageFileDetails = getimagesize($_FILES['image_file']['tmp_name']);

        if (isset($_FILES['image_file']['size']) && $_FILES['image_file']['size'] > 5242880) {  // 5242880 is 5 MB (can be set to any desirable value)
            sendResponse(400, false, 'File must be under 5 MB');
        }

        $allowedMimeTypes = ['image/jpg', 'image/jpeg', 'image/gif', 'image/png'];

        $mimeType = strtolower($imageFileDetails['mime']);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            sendResponse(400, false, 'File type not supported');
        }

        switch ($mimeType) {
            case 'image/jpg':
                $fileExtension = '.jpg';
                break;
            case 'image/jpeg':
                $fileExtension = '.jpeg';
                break;
            case 'image/gif':
                $fileExtension = '.gif';
                break;
            case 'image/png':
                $fileExtension = '.png';
                break;
            default:
                $fileExtension = null;
        }

        if (!$fileExtension) {
            sendResponse(400, false, 'No valid file extension found for MIME type');
        }

        $image = new Image(null, $task_id, $jsonImageAttributes->title, $jsonImageAttributes->filename . $fileExtension, $mimeType);

        $title = $image->getTitle();
        $filename = $image->getFilename();
        $mimeType = $image->getMimeType();

        $query = $readDB->prepare('SELECT images.id FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND tasks.user_id = :user_id AND images.filename = :filename');
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->bindParam(':filename', $filename, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount !== 0) {
            sendResponse(409, false, 'A file with this name already exists - try a different one.');
        }

        $writeDB->beginTransaction();

        $query = $writeDB->prepare('INSERT INTO images (task_id, title, filename, mime_type) VALUES (:task_id, :title, :filename, :mime_type)');
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':title', $title, PDO::PARAM_STR);
        $query->bindParam(':filename', $filename, PDO::PARAM_STR);
        $query->bindParam(':mime_type', $mimeType, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) $writeDB->rollBack();
            sendResponse(500, false, 'Failed to upload image');
        }

        $lastImageId = $writeDB->lastInsertId();

        $query = $writeDB->prepare('SELECT images.id, images.task_id, images.title, images.filename, images.mime_type FROM images, tasks WHERE images.task_id = tasks.id AND images.id = :image_id AND tasks.id = :task_id AND tasks.user_id = :user_id');
        $query->bindParam(':image_id', $lastImageId, PDO::PARAM_INT);
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) $writeDB->rollBack();
            sendResponse(500, false, 'Failed to retrieve image attributes after upload - try uploading image again');
        }

        $imageArray = [];

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image($row['id'], $row['task_id'], $row['title'], $row['filename'], $row['mime_type']);
            $imageArray[] = $image->returnImageAsArray();
        }

        $image->saveImageFile($_FILES['image_file']['tmp_name']);

        $writeDB->commit();

        sendResponse(201, true, 'Image uploaded successfully', false, $imageArray);

    } catch (PDOException $e) {
        error_log("Connection error - $e", 0);
        if ($writeDB->inTransaction()) $writeDB->rollBack();
        sendResponse(500, false, 'Database connection error');
    } catch (ImageException $e) {
        if ($writeDB->inTransaction()) $writeDB->rollBack();
        sendResponse(500, false, $e->getMessage());
    }
}

function getImageAttributesRoute($readDB, $task_id, $image_id, $returned_user_id)
{
    try {
        $query = $readDB->prepare('SELECT images.id, images.task_id, images.title, images.filename, images.mime_type FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND images.id = :image_id AND tasks.user_id = :user_id');
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':image_id', $image_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            sendResponse(404, false, 'Image not found');
        }

        $imageArray = [];

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image($row['id'], $row['task_id'], $row['title'], $row['filename'], $row['mime_type']);
            $imageArray[] = $image->returnImageAsArray();
        }

        sendResponse(200, true, null, true, $imageArray);
    } catch (PDOException $e) {
        error_log("Database query error - $e", 0);
        sendResponse(500, false, 'Failed to get image attributes');
    } catch (ImageException $e) {
        sendResponse(500, false, $e->getMessage());
    }
}

function getImageRoute($readDB, $task_id, $image_id, $returned_user_id)
{
    try {
        $query = $readDB->prepare('SELECT images.id, images.task_id, images.title, images.filename, images.mime_type FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND images.id = :image_id AND tasks.user_id = :user_id');
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':image_id', $image_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            sendResponse(404, false, 'Image not found');
        }

        $image = null;

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image($row['id'], $row['task_id'], $row['title'], $row['filename'], $row['mime_type']);
        }

        if ($image === null) {
            sendResponse(500, false, "Image not found");
        }

        $image->returnImageFile();
    } catch (PDOException $e) {
        error_log("Database query error - $e", 0);
        sendResponse(500, false, 'Error getting image');
    } catch (ImageException $e) {
        sendResponse(500, false, $e->getMessage());
    }
}

function updateImageAttributesRoute($writeDB, $task_id, $image_id, $returned_user_id)
{
    try {
        if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
            sendResponse(400, false, 'Content-type header not set to JSON');
        }

        $rawPatchData = file_get_contents('php://input');
        $jsonData = json_decode($rawPatchData);

        if (!$jsonData) {
            sendResponse(400, false, 'Request body not valid JSON');
        }

        $title_updated = false;
        $filename_updated = false;

        $queryFields = "";

        if (isset($jsonData->title)) {
            $title_updated = true;
            $queryFields .= "images.title = :title, ";
        }

        if (isset($jsonData->filename)) {
            if (strpos($jsonData->filename, '.') !== false) {
                sendResponse(400, false, 'Filename must not contain file extension');
            }

            $filename_updated = true;
            $queryFields .= "images.filename = :filename, ";
        }

        $queryFields = rtrim($queryFields, ', ');

        if ($title_updated === false && $filename_updated === false) {
            sendResponse(400, false, 'No image fields provided');
        }

        $writeDB->beginTransaction();

        $query = $writeDB->prepare('SELECT images.id, images.task_id, images.title, images.filename, images.mime_type FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND images.id = :image_id AND tasks.user_id = :user_id');
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':image_id', $image_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) $writeDB->rollBack();
            sendResponse(404, false, 'Image not found');
        }

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image($row['id'], $row['task_id'], $row['title'], $row['filename'], $row['mime_type']);
        }

        // INNER JOIN selects records that have matching values in both tables.
        $queryString = "UPDATE images INNER JOIN tasks ON images.task_id = tasks.id SET $queryFields WHERE images.task_id = :task_id AND images.id = :image_id AND tasks.user_id = :user_id";
        $query = $writeDB->prepare($queryString);

        if ($title_updated) {
            $image->setTitle($jsonData->title);
            $updated_title = $image->getTitle();
            $query->bindParam(':title', $updated_title, PDO::PARAM_STR);
        }

        if ($filename_updated) {
            $originalFilename = $image->getFilename();
            $image->setFilename($jsonData->filename . $image->getFileExtension());
            $updated_filename = $image->getFilename();
            $query->bindParam(':filename', $updated_filename, PDO::PARAM_STR);
        }

        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':image_id', $image_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) $writeDB->rollBack();
            sendResponse(400, false, 'Image attributes not updated');
        }

        // writeDB is used because there might not have been enough time to send data to readDB server
        $query = $writeDB->prepare('SELECT images.id, images.task_id, images.title, images.filename, images.mime_type FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND images.id = :image_id AND tasks.user_id = :user_id');
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':image_id', $image_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) $writeDB->rollBack();
            sendResponse(404, false, 'No image found');
        }

        $imageArray = [];

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image($row['id'], $row['task_id'], $row['title'], $row['filename'], $row['mime_type']);
            $imageArray[] = $image->returnImageAsArray();
        }

        if ($filename_updated) $image->renameImageFile($originalFilename, $updated_filename);

        $writeDB->commit();

        sendResponse(200, true, 'Image attributes updated successfully', false, $imageArray);
    } catch (PDOException $e) {
        error_log("Database query error - $e", 0);
        if ($writeDB->inTransaction()) $writeDB->rollBack();
        sendResponse(500, false, 'Failed to update image attributes');
    } catch (ImageException $e) {
        if ($writeDB->inTransaction()) $writeDB->rollBack();
        sendResponse(400, false, $e->getMessage());
    }
}

function deleteImageRoute($writeDB, $task_id, $image_id, $returned_user_id)
{
    try {
        $writeDB->beginTransaction();

        $query = $writeDB->prepare('SELECT images.id, images.task_id, images.title, images.filename, images.mime_type FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND images.id = :image_id AND tasks.user_id = :user_id');
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':image_id', $image_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            $writeDB->rollBack();
            sendResponse(404, false, 'Image not found');
        }

        $image = null;

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $image = new Image($row['id'], $row['task_id'], $row['title'], $row['filename'], $row['mime_type']);
        }

        if ($image === null) {
            $writeDB->rollBack();
            sendResponse(500, false, "Image not found");
        }

        $query = $writeDB->prepare('DELETE images FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND images.id = :image_id AND tasks.user_id = :user_id');
        $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
        $query->bindParam(':image_id', $image_id, PDO::PARAM_INT);
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            $writeDB->rollBack();
            sendResponse(404, false, 'Image not found');
        }

        $image->deleteImageFile();

        $writeDB->commit();

        sendResponse(200, true, 'Image deleted');
    } catch (PDOException $e) {
        error_log("Database query error - $e", 0);
        // since transaction begins at the very top of the try block no need to check for it
        $writeDB->rollBack();
        sendResponse(500, false, 'Failed to delete image');
    } catch (ImageException $e) {
        $writeDB->rollBack();
        sendResponse(500, false, $e->getMessage());
    }
}

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectReadDB();
} catch (PDOException $e) {
    error_log("Connection error - $e", 0);
    sendResponse(500, false, 'Database connection error');
}

$returned_user_id = getUser($writeDB);

// tasks/1/images/5/attributes - image attributes
if (array_key_exists('task_id', $_GET) &&
    array_key_exists('image_id', $_GET) &&
    array_key_exists('attributes', $_GET)
) {
    $task_id = $_GET['task_id'];
    $image_id = $_GET['image_id'];
    $attributes = $_GET['attributes'];

    if ($task_id == '' || !is_numeric($task_id) || $image_id == '' || !is_numeric($image_id)) {
        sendResponse(400, false, 'Task ID or Image ID cannot be blank and must be numeric');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        getImageAttributesRoute($readDB, $task_id, $image_id, $returned_user_id);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        updateImageAttributesRoute($writeDB, $task_id, $image_id, $returned_user_id);
    } else {
        sendResponse(405, false, 'Request method not allowed');
    }
// tasks/1/images/5 - image file itself
} elseif (array_key_exists('task_id', $_GET) && array_key_exists('image_id', $_GET)) {
    $task_id = $_GET['task_id'];
    $image_id = $_GET['image_id'];

    if ($task_id == '' || !is_numeric($task_id) || $image_id == '' || !is_numeric($image_id)) {
        sendResponse(400, false, 'Task ID or Image ID cannot be blank and must be numeric');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        getImageRoute($readDB, $task_id, $image_id, $returned_user_id);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        deleteImageRoute($writeDB, $task_id, $image_id, $returned_user_id);
    } else {
        sendResponse(405, false, 'Request method not allowed');
    }
// tasks/5/images
} elseif (array_key_exists('task_id', $_GET) && !array_key_exists('image_id', $_GET)) {
    $task_id = $_GET['task_id'];

    if ($task_id == '' || !is_numeric($task_id)) {
        sendResponse(400, false, 'Task ID cannot be blank and must be numeric');
    }

    // how to post an image:
    // url - tasks/{id}/images
    // body - form-data
    // key - attributes, value - {"title":"Some title", "filename":"some filename"}
    // key - image_file (type - file), value - pick an image from some directory
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        uploadImageRoute($readDB, $writeDB, $task_id, $returned_user_id);
    } else {
        sendResponse(405, false, 'Request method not allowed');
    }
} else {
    sendResponse(404, false, 'Endpoint not found');
}

