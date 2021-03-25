<?php

require_once '../DB.php';
require_once '../models/Response.php';

try {
    $writeDB = DB::connectWriteDB();
} catch (PDOException $e) {
    error_log("Connection error - $e", 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage('Database connection error');
    $response->send();
    exit;
}

if (array_key_exists('id', $_GET)) {
    $id = $_GET['id'];

    if ($id === '' || !is_numeric($id)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $id === '' && $response->addMessage('Session ID cannot be blank');
        !is_numeric($id) && $response->addMessage('Session ID must be numeric');
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

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {
            $query = $writeDB->prepare('DELETE FROM sessions WHERE id = :id AND access_token = :access_token');
            $query->bindParam(':id', $id, PDO::PARAM_STR);
            $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Failed to log out from this session using access token provided");
                $response->send();
                exit;
            }

            $data = [];
            $data['id'] = intval($id);

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Logged out");
            $response->setData($data);
            $response->send();
            exit;
        } catch (PDOException $e) {
            error_log("Connection error - $e", 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage('There was an issue logging out - please try again');
            $response->send();
            exit;
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        if (!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
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

        if (!isset($jsonData->refresh_token) || strlen($jsonData->refresh_token) < 1) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $jsonData->refresh_token ?? $response->addMessage('Refresh token not supplied');
            isset($jsonData->refresh_token) && $jsonData->refresh_token < 1 && $response->addMessage('Refresh token cannot be blank');
            $response->send();
            exit;
        }

        try {
            $refresh_token = $jsonData->refresh_token;

            $query = $writeDB->prepare('SELECT sessions.id AS session_id, user_id, access_token, refresh_token, access_token_expiration, refresh_token_expiration, is_active, login_attempts FROM sessions, users WHERE sessions.user_id = users.id AND sessions.id = :id AND access_token = :access_token AND refresh_token = :refresh_token');
            $query->bindParam(':id', $id, PDO::PARAM_INT);
            $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
            $query->bindParam(':refresh_token', $refresh_token, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token or refresh token incorrect");
                $response->send();
                exit;
            }

            $row = $query->fetch(PDO::FETCH_ASSOC);

            $returned_session_id = $row['session_id'];
            $returned_user_id = $row['user_id'];
            $returned_access_token = $row['access_token'];
            $returned_refresh_token = $row['refresh_token'];
            $returned_access_token_expiration = $row['access_token_expiration'];
            $returned_refresh_token_expiration = $row['refresh_token_expiration'];
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

            if (strtotime($returned_refresh_token_expiration) < time()) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage('Refresh token has expired - please log in again');
                $response->send();
                exit;
            }

            // time() makes it more unique
            $access_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
            $refresh_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

            $access_token_expiration = 1200;  // 20 minutes
            $refresh_token_expiration = 1209600;  // 14 days

            $query = $writeDB->prepare(
                'UPDATE sessions SET access_token = :access_token, access_token_expiration = DATE_ADD(NOW(), INTERVAL :access_token_expiration SECOND),
                            refresh_token = :refresh_token, refresh_token_expiration = DATE_ADD(NOW(), INTERVAL :refresh_token_expiration SECOND)
                            WHERE id = :id AND user_id = :user_id AND access_token = :returned_access_token AND refresh_token = :returned_refresh_token');
            $query->bindParam(':id', $id, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_user_id, PDO::PARAM_INT);
            $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
            $query->bindParam(':access_token_expiration', $access_token_expiration, PDO::PARAM_INT);
            $query->bindParam(':refresh_token', $refresh_token, PDO::PARAM_STR);
            $query->bindParam(':refresh_token_expiration', $refresh_token_expiration, PDO::PARAM_INT);
            $query->bindParam(':returned_access_token', $returned_access_token, PDO::PARAM_STR);
            $query->bindParam(':returned_refresh_token', $returned_refresh_token, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token could not be refreshed - please log in again");
                $response->send();
                exit;
            }

            $data = [];
            $data['id'] = intval($returned_session_id);
            $data['access_token'] = $access_token;
            $data['access_token_expiration'] = $access_token_expiration;
            $data['refresh_token'] = $refresh_token;
            $data['refresh_token_expiration'] = $refresh_token_expiration;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage('Token refreshed');
            $response->setData($data);
            $response->send();
            exit;
        } catch (PDOException $e) {
            error_log("Connection error - $e", 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage('There was an issue refreshing access token - please log in again');
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
} elseif (empty($_GET)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage('Request method not allowed');
        $response->send();
        exit;
    }

    // delay post request by one second so a hacker can't hack an account using a dictionary
    // because with one request per second instead of a hundred or more (depends on the server)
    // it will take him a long time to accomplish
    sleep(1);

    if (!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('Content-type header not set to JSON');
        $response->send();
        exit;
    }

    $rawPostData = file_get_contents('php://input');
    $jsonData = json_decode($rawPostData);

    if (!$jsonData) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('Request body not valid JSON');
        $response->send();
        exit;
    }

    if (!isset($jsonData->username) || !isset($jsonData->password)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $jsonData->username ?? $response->addMessage('Username field is required');
        $jsonData->password ?? $response->addMessage('Password field is required');
        $response->send();
        exit;
    }

    if (strlen($jsonData->username ) < 1|| strlen($jsonData->username) > 255 ||
        strlen($jsonData->password ) < 1 || strlen($jsonData->password) > 255
    ) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        strlen($jsonData->username) < 1 ? $response->addMessage('Username cannot be blank') : false;
        strlen($jsonData->username) > 255 ? $response->addMessage('Username cannot greater than 255 characters') : false;
        strlen($jsonData->password) < 1 ? $response->addMessage('Password cannot be blank') : false;
        strlen($jsonData->password) > 255 ? $response->addMessage('Password cannot greater than 255 characters') : false;
        $response->send();
        exit;
    }

    try {
        $username = $jsonData->username;
        $password = $jsonData->password;

        $query = $writeDB->prepare('SELECT * FROM users WHERE username = :username');
        $query->bindParam(':username', $username, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("Username or password is incorrect");
            $response->send();
            exit;
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_id = $row['id'];
        $returned_full_name = $row['full_name'];
        $returned_username = $row['username'];
        $returned_password = $row['password'];
        $returned_is_active = $row['is_active'];
        $returned_login_attempts = $row['login_attempts'];

        if ($returned_is_active !== 'Y') {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("User account not active");
            $response->send();
            exit;
        }

        if ($returned_login_attempts > 2) {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("User account currently locked");
            $response->send();
            exit;
        }

        if (!password_verify($password, $returned_password)) {
            $query = $writeDB->prepare('UPDATE users SET login_attempts = (login_attempts + 1) WHERE id = :id');
            $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
            $query->execute();

            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("Username or password is incorrect");
            $response->send();
            exit;
        }

        // time() makes it more unique
        $access_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
        $refresh_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

        $access_token_expiration = 1200;  // in seconds (20 min)
        $refresh_token_expiration = 1209600;  // 14 days

        try {
            // turn off autocommit mode
            $writeDB->beginTransaction();
            $query = $writeDB->prepare('UPDATE users SET login_attempts = 0 WHERE id = :id');
            $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
            $query->execute();

            $query = $writeDB->prepare('INSERT INTO sessions (user_id, access_token, access_token_expiration, refresh_token, refresh_token_expiration) VALUES (:user_id, :access_token, DATE_ADD(NOW(), INTERVAL :access_token_expiration SECOND), :refresh_token, date_add(NOW(), INTERVAL :refresh_token_expiration SECOND))');
            $query->bindParam(':user_id', $returned_id, PDO::PARAM_INT);
            $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
            $query->bindParam(':access_token_expiration', $access_token_expiration, PDO::PARAM_INT);
            $query->bindParam(':refresh_token', $refresh_token, PDO::PARAM_STR);
            $query->bindParam(':refresh_token_expiration', $refresh_token_expiration, PDO::PARAM_INT);
            $query->execute();

            $lastSessionId = $writeDB->lastInsertId();

            // save data to the database
            $writeDB->commit();

            $data = [];
            $data['id'] = intval($lastSessionId);
            $data['access_token'] = $access_token;
            $data['access_token_expiration'] = $access_token_expiration;
            $data['refresh_token'] = $refresh_token;
            $data['refresh_token_expiration'] = $refresh_token_expiration;

            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($data);
            $response->send();
            exit;
        } catch (PDOException $e) {
            // roll back all changes to the database
            // it's done in case of multiple queries so if one fails everything reverts back to the previous state
            $writeDB->rollBack();
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage('There was an issue logging in - please try again');
            $response->send();
            exit;
        }
    } catch (PDOException $e) {
        // creating log files is insecure when dealing with passwords
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage('There was an issue logging in');
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
