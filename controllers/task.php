<?php

require_once '../DB.php';
require_once '../models/Task.php';
require_once '../models/Response.php';
require_once '../models/Image.php';

function retrieveTaskImages($dbCon, $task_id, $returned_user_id) {
    $query = $dbCon->prepare('SELECT images.id, images.task_id, images.title, images.filename, images.mime_type FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND tasks.user_id = :user_id');
    $query->bindParam(':task_id', $task_id, PDO::PARAM_INT);
    $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
    $query->execute();

    $imageArray = [];

    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $image = new Image($row['id'], $row['task_id'], $row['title'], $row['filename'], $row['mime_type']);
        $imageArray[] = $image->returnImageAsArray();
    }

    return $imageArray;
}

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectReadDB();
} catch (PDOException $e) {
    error_log("Connection error - $e", 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage('Database connection error');
    $response->send();
    exit;
}

if (!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    !isset($_SERVER['HTTP_AUTHORIZATION']) && $response->addMessage('Access token missing from the header');
    strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 && $response->addMessage('Access token cannot be blank');
    $response->send();
    exit;
}

$access_token = $_SERVER['HTTP_AUTHORIZATION'];

try {
    $query = $writeDB->prepare('SELECT user_id, access_token_expiration, is_active, login_attempts FROM sessions, users WHERE sessions.user_id = users.id AND access_token = :access_token');
    $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount === 0) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage('Invalid access token');
        $response->send();
        exit;
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_user_id = $row['user_id'];
    $returned_access_token_expiration = $row['access_token_expiration'];
    $returned_is_active = $row['is_active'];
    $returned_login_attempts = $row['login_attempts'];

    if ($returned_is_active !== 'Y') {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage('User account not active');
        $response->send();
        exit;
    }

    if ($returned_login_attempts > 2) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage('User account locked');
        $response->send();
        exit;
    }

    if (strtotime($returned_access_token_expiration) < time()) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage('Access token has expired');
        $response->send();
        exit;
    }

} catch (PDOException $e) {
    error_log("Database query error - $e", 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage('There was an issue authenticating - please try again');
    $response->send();
    exit;
}

if (array_key_exists('id', $_GET)) {
    $id = $_GET['id'];

    if ($id == '' || !is_numeric($id)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('Task ID cannot be blank or must be numeric');
        $response->send();
        exit;
    }

    // "POST" method is absent here because it's not used on a single post url like /tasks/1
    // it's used on /tasks url
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed from tasks WHERE id = :id AND user_id = :user_id');
            $query->bindParam(':id', $id, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage('Task not found');
                $response->send();
                exit;
            }

            $taskArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $imageArray = retrieveTaskImages($readDB, $id, $returned_user_id);
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $data = [];
            $data['rows_returned'] = $rowCount;
            $data['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($data);
            $response->send();
            exit;
        } catch (PDOException $e) {
            error_log("Database query error - $e", 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage('Failed to get task');
            $response->send();
            exit;
        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        } catch (ImageException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {
            // get all task's images
            $imageQuery = $readDB->prepare('SELECT images.id, images.task_id, images.title, images.filename, images.mime_type FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND tasks.user_id = :user_id');
            $imageQuery->bindParam(':task_id', $id, PDO::PARAM_INT);
            $imageQuery->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $imageQuery->execute();

            // select and delete queries should be assigned to variables with different names ($imageQuery and $query)
            // because delete query variable will overwrite select query variable in while condition if they have the same name
            while ($row = $imageQuery->fetch(PDO::FETCH_ASSOC)) {
                $writeDB->beginTransaction();
                $image = new Image($row['id'], $row['task_id'], $row['title'], $row['filename'], $row['mime_type']);
                $imageId = $image->getId();

                $query = $writeDB->prepare('DELETE images FROM images, tasks WHERE images.task_id = tasks.id AND tasks.id = :task_id AND images.id = :image_id AND tasks.user_id = :user_id');
                $query->bindParam(':task_id', $id, PDO::PARAM_INT);
                $query->bindParam(':image_id', $imageId, PDO::PARAM_INT);
                $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
                $query->execute();

                $image->deleteImageFile();

                $writeDB->commit();
            }

            $query = $writeDB->prepare('DELETE FROM tasks WHERE id = :id AND user_id = :user_id');
            $query->bindParam(':id', $id, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage('Task not found');
                $response->send();
                exit;
            }

            $taskImageFolder = "../task_images/$id";

            if (is_dir($taskImageFolder)) rmdir($taskImageFolder);

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage('Task deleted');
            $response->send();
            exit;
        } catch (PDOException $e) {
            error_log("Database query error - $e", 0);
            if ($writeDB->inTransaction()) $writeDB->rollBack();
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage('Failed to get task');
            $response->send();
            exit;
        } catch (ImageException $e) {
            if ($writeDB->inTransaction()) $writeDB->rollBack();
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        try {
            if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage('Content-type header not set to JSON');
                $response->send();
                exit;
            }

            $rawPatchData = file_get_contents('php://input');
            $jsonData = json_decode($rawPatchData);

            if (!$jsonData) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage('Request body not valid JSON');
                $response->send();
                exit;
            }

            $title_updated = false;
            $description_updated = false;
            $deadline_updated = false;
            $completed_updated = false;

            $queryFields = "";

            if (isset($jsonData->title)) {
                $title_updated = true;
                $queryFields .= "title = :title, ";
            }

            if (isset($jsonData->description)) {
                $description_updated = true;
                $queryFields .= "description = :description, ";
            }

            if (isset($jsonData->deadline)) {
                $deadline_updated = true;
                $queryFields .= "deadline = STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), ";
            }

            if (isset($jsonData->completed)) {
                $completed_updated = true;
                $queryFields .= "completed = :completed, ";
            }

            $queryFields = rtrim($queryFields, ', ');

            if ($title_updated === false &&
                $description_updated === false &&
                $deadline_updated === false &&
                $completed_updated === false
            ) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("No task fields provided");
                $response->send();
                exit;
            }

            $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed from tasks WHERE id = :id AND user_id = :user_id');
            $query->bindParam(':id', $id, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage('Task not found');
                $response->send();
                exit;
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
            }

            $queryString = "UPDATE tasks SET $queryFields WHERE id = :id AND user_id = :user_id";
            $query = $writeDB->prepare($queryString);

            $query->bindParam(':id', $id, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);

            if ($title_updated) {
                $task->setTitle($jsonData->title);
                $updated_title = $task->getTitle();
                $query->bindParam(':title', $updated_title, PDO::PARAM_STR);
            }

            if ($description_updated) {
                $task->setDescription($jsonData->description);
                $updated_description = $task->getDescription();
                $query->bindParam(':description', $updated_description, PDO::PARAM_STR);
            }

            if ($deadline_updated) {
                $task->setDeadline($jsonData->deadline);
                $updated_deadline= $task->getDeadline();
                $query->bindParam(':deadline', $updated_deadline, PDO::PARAM_STR);
            }

            if ($completed_updated) {
                $task->setCompleted($jsonData->completed);
                $updated_completed= $task->getCompleted();
                $query->bindParam(':completed', $updated_completed, PDO::PARAM_STR);
            }

            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage('Task not updated');
                $response->send();
                exit;
            }

            // writeDB is used because there might not have been enough time to send data to readDB server
            $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed from tasks WHERE id = :id AND user_id = :user_id');
            $query->bindParam(':id', $id, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to retrieve task after creation");
                $response->send();
                exit;
            }

            $taskArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $imageArray = retrieveTaskImages($writeDB, $id, $returned_user_id);
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $data = [];
            $data['rows_returned'] = $rowCount;
            $data['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage('Task updated');
            $response->setData($data);
            $response->send();
            exit;
        } catch (PDOException $e) {
            error_log("Database query error - $e", 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to update task - check your data for errors");
            $response->send();
            exit;
        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        } catch (ImageException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        }
    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage('Request method not allowed');
        $response->send();
        exit;
    }
} elseif (array_key_exists('completed', $_GET)) {
    $completed = $_GET['completed'];

    if ($completed !== 'Y' && $completed !== 'N') {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('Completed filter must be Y or N');
        $response->send();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed from tasks WHERE completed = :completed AND user_id = :user_id');
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_user_id);
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $data = [];
            $data['rows_returned'] = $rowCount;
            $data['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($data);
            $response->send();
            exit;
        } catch (PDOException $e) {
            error_log("Database query error - $e", 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get tasks");
            $response->send();
            exit;
        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        } catch (ImageException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        }

    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage('Request method not allowed');
        $response->send();
        exit;
    }
} elseif (array_key_exists('page', $_GET)) {
    $page = $_GET['page'];

    if ($page == '' || !is_numeric($page)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('Page number cannot be blank and must be numeric');
        $response->send();
    }

    $limitPerPage = 5;

    try {
        $query = $readDB->prepare('SELECT COUNT(id) AS totalNumOfTasks from tasks WHERE user_id = :user_id');
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->execute();

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $taskCount = intval($row['totalNumOfTasks']);
        $numOfPages = ceil($taskCount / $limitPerPage);

        // if there is no tasks make number of pages 1
        if ($numOfPages == 0) {
            $numOfPages = 1;
        }

        if ($page > $numOfPages || $page == 0) {
            $response = new Response();
            $response->setHttpStatusCode(404);
            $response->setSuccess(false);
            $response->addMessage('Page not found');
            $response->send();
            exit;
        }

        $offset = $page == 1 ? 0 : ($limitPerPage * ($page - 1));

        $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed from tasks WHERE user_id = :user_id LIMIT :limit OFFSET :offset');
        $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
        $query->bindParam(':limit', $limitPerPage, PDO::PARAM_INT);
        $query->bindParam(':offset', $offset, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        $taskArray = [];

        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_user_id);
            $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);
            $taskArray[] = $task->returnTaskAsArray();
        }

        $data = [];
        $data['rows_returned'] = $rowCount;
        $data['total_rows'] = $taskCount;
        $data['total_pages'] = $numOfPages;
        $data['has_next_page'] = $page < $numOfPages;
        $data['has_previous_page'] = $page > 1;
        $data['tasks'] = $taskArray;

        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->toCache(true);
        $response->setData($data);
        $response->send();
        exit;
    } catch (PDOException $e) {
        error_log("Database query error - $e", 0);
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("Failed to get tasks");
        $response->send();
        exit;
    } catch (TaskException $e) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage($e->getMessage());
        $response->send();
        exit;
    } catch (ImageException $e) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage($e->getMessage());
        $response->send();
        exit;
    }
} elseif (empty($_GET)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed from tasks WHERE user_id = :user_id');
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $imageArray = retrieveTaskImages($readDB, $row['id'], $returned_user_id);
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'], $imageArray);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $data = [];
            $data['rows_returned'] = $rowCount;
            $data['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($data);
            $response->send();
            exit;
        } catch (PDOException $e) {
            error_log("Database query error - $e", 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get tasks");
            $response->send();
            exit;
        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        } catch (ImageException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        }
        // if your client needs to upload images at the same time as creating the task
        // it should do this in a separate POST request to the images route for the given task
        // after the task has been successfully created.
        // This allows for the client to create the task and upload the attachments in the background,
        // resulting in a better UX
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage('Content-type header not set to JSON');
                $response->send();
                exit;
            }

            $rawPOSTData = file_get_contents('php://input');
            $jsonData = json_decode($rawPOSTData);

            if (!$jsonData) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage('Request body not valid JSON');
                $response->send();
                exit;
            }

            if (!isset($jsonData->title) || !isset($jsonData->completed)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                !isset($jsonData->title) ? $response->addMessage('Title field is required') : false;
                !isset($jsonData->completed) ? $response->addMessage('Completed field is required') : false;
                $response->send();
                exit;
            }

            $newTask = new Task(
                null,
                $jsonData->title,
                $jsonData->description ?? null,
                $jsonData->deadline ?? null,
                $jsonData->completed
            );

            $title = $newTask->getTitle();
            $description = $newTask->getDescription();
            $deadline = $newTask->getDeadline();
            $completed = $newTask->getCompleted();

            $query = $writeDB->prepare('INSERT INTO tasks (user_id, title, description, deadline, completed) VALUES(:user_id, :title, :description, STR_TO_DATE(:deadline, \'%d/%m/%Y %H:%i\'), :completed)');
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $query->bindParam(':title', $title, PDO::PARAM_STR);
            $query->bindParam(':description', $description, PDO::PARAM_STR);
            $query->bindParam(':deadline', $deadline, PDO::PARAM_STR);
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to insert task into database");
                $response->send();
                exit;
            }

            // retrieve ID of the created task
            $lastTaskId = $writeDB->lastInsertId();

            // writeDB is used because there might not have been enough time to send data to readDB server
            $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed from tasks WHERE id = :id AND user_id = :user_id');
            $query->bindParam(':id', $lastTaskId, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to retrieve task after creation");
                $response->send();
                exit;
            }

            $taskArray = [];

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
                $taskArray[] = $task->returnTaskAsArray();
            }

            $data = [];
            $data['rows_returned'] = $rowCount;
            $data['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->addMessage('Task created');
            $response->setData($data);
            $response->send();
            exit;
        } catch (PDOException $e) {
            error_log("Database query error - $e", 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to insert task into database - check submitted data for errors");
            $response->send();
            exit;
        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit;
        }
    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage('Request method not allowed');
        $response->send();
        exit;
    }
} else {
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage('Endpoint not found');
    $response->send();
    exit;
}
