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

// handle options request for CORS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    $response = new Response();
    $response->setHttpStatusCode(200);
    $response->setSuccess(true);
    $response->send();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage('Request method not allowed');
    $response->send();
    exit;
}

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

if (!isset($jsonData->full_name) || !isset($jsonData->username) || !isset($jsonData->password)) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $jsonData->full_name ?? $response->addMessage('Full name field is required');
    $jsonData->username ?? $response->addMessage('Username field is required');
    $jsonData->password ?? $response->addMessage('Password field is required');
    $response->send();
    exit;
}

if (strlen($jsonData->full_name ) < 1 || strlen($jsonData->full_name) > 255 ||
    strlen($jsonData->username ) < 1|| strlen($jsonData->username) > 255 ||
    strlen($jsonData->password ) < 1 || strlen($jsonData->password) > 255
) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    strlen($jsonData->full_name) < 1 ? $response->addMessage('Full name cannot be blank') : false;
    strlen($jsonData->full_name) > 255 ? $response->addMessage('Full name cannot greater than 255 characters') : false;
    strlen($jsonData->username) < 1 ? $response->addMessage('Username name cannot be blank') : false;
    strlen($jsonData->username) > 255 ? $response->addMessage('Username name cannot greater than 255 characters') : false;
    strlen($jsonData->password) < 1 ? $response->addMessage('Password name cannot be blank') : false;
    strlen($jsonData->password) > 255 ? $response->addMessage('Password name cannot greater than 255 characters') : false;
    $response->send();
    exit;
}

$full_name = trim($jsonData->full_name);
$username = trim($jsonData->username);
// no need to trim password of whitespace because it's a valid character
$password = $jsonData->password;

try {
    $query = $writeDB->prepare('SELECT id from users WHERE username = :username');
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount !== 0) {
        $response = new Response();
        $response->setHttpStatusCode(409);
        $response->setSuccess(false);
        $response->addMessage("Username already exists");
        $response->send();
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $query = $writeDB->prepare('INSERT INTO users (full_name, username, password) VALUES(:full_name, :username, :password)');
    $query->bindParam(':full_name', $full_name, PDO::PARAM_STR);
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->bindParam(':password', $hashed_password, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount === 0) {
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage('There was an issue creating a user account - please try again');
        $response->send();
        exit;
    }

    $lastUserId = $writeDB->lastInsertId();

    $data = [];
    $data['id'] = $lastUserId;
    $data['full_name'] = $full_name;
    $data['username'] = $username;

    $response = new Response();
    $response->setHttpStatusCode(201);
    $response->setSuccess(true);
    $response->addMessage('User created');
    $response->setData($data);
    $response->send();
    exit;
} catch (PDOException $e) {
    error_log("Connection error - $e", 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage('There was an issue creating a user account - please try again');
    $response->send();
    exit;
}
