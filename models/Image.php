<?php

class ImageException extends Exception { }

class Image
{
    private $_id;
    private $_task_id;
    private $_title;
    private $_filename;
    private $_mime_type;
    private $_uploadFolderLocation;

    public function __construct($id, $task_id, $title, $filename, $mime_type)
    {
        $this->setId($id);
        $this->setTaskId($task_id);
        $this->setTitle($title);
        $this->setFilename($filename);
        $this->setMimeType($mime_type);
        $this->_uploadFolderLocation = '../task_images/';
    }

    public function getId()
    {
        return $this->_id;
    }

    public function getTaskId()
    {
        return $this->_task_id;
    }

    public function getTitle()
    {
        return $this->_title;
    }

    public function getFilename()
    {
        return $this->_filename;
    }

    public function getFileExtension()
    {
        $fileNameArray = explode(".", $this->_filename);
        $extensionIndex = count($fileNameArray) - 1;
        $fileExtension = $fileNameArray[$extensionIndex];
        return ".$fileExtension";
    }

    public function getMimeType()
    {
        return $this->_mime_type;
    }

    public function getUploadFolderLocation()
    {
        return $this->_uploadFolderLocation;
    }

    public function getImageUrl()
    {
        $httpsOrHttp = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $url = '/tasks/' . $this->getTaskId() . '/images/' . $this->getId();

        return $httpsOrHttp . '://' . $host . $url;
    }

    public function returnImageFile()
    {
        $filePath = $this->getUploadFolderLocation() . $this->getTaskId() . '/' . $this->getFilename();

        if (!file_exists($filePath)) {
            throw new ImageException('Image file not found');
        }

        header('Content-Type: ' . $this->getMimeType());
        header('Content-Disposition: inline; filename="' . $this->getFilename() . '"');

        if (!readfile($filePath)) {
            // can't send ordinary JSON response since content-type is already set to image file type
            http_response_code(404);
            exit;
        }

        exit;
    }

    public function setId($id)
    {
        // 9223372036854775807 is the maximum value for the MySQL big integer datatype
        if ($id !== null && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)) {
            throw new ImageException('Image ID error');
        }

        $this->_id = $id;
    }

    public function setTaskId($task_id)
    {
        // 9223372036854775807 is the maximum value for the MySQL big integer datatype
        if ($task_id !== null && (!is_numeric($task_id) || $task_id <= 0 || $task_id > 9223372036854775807 || $this->_task_id !== null)) {
            throw new ImageException('Image ID error');
        }

        $this->_task_id = $task_id;
    }

    public function setTitle($title)
    {
        if (strlen($title) < 1 || strlen($title) > 255) {
            throw new TaskException('Image title error');
        }

        $this->_title = $title;
    }

    public function setFilename($filename)
    {
        if (strlen($filename) < 1 || strlen($filename) > 30 || preg_match('/^[a-zA-Z0-9\s_-]+\.(jpg|jpeg|gif|png)$/', $filename) != 1) {
            throw new ImageException('Image filename error - must be between 1 and 30 characters and only be .jpg .gif .png');
        }

        $this->_filename = $filename;
    }

    public function setMimeType($mime_type)
    {
        if (strlen($mime_type) < 1 || strlen($mime_type) > 255) {
            throw new ImageException('Image MIME type error');
        }

        $this->_mime_type= $mime_type;
    }

    public function saveImageFile($tempFilename)
    {
        $uploadedFilePath = $this->getUploadFolderLocation() . $this->getTaskId() . '/' . $this->getFilename();

        if (!is_dir($this->getUploadFolderLocation() . $this->getTaskId())) {
            if (!mkdir($this->getUploadFolderLocation() . $this->getTaskId())) {
                throw new ImageException('Failed to create image upload folder for task');
            }
        }

        if (!file_exists($tempFilename)) {
            throw new ImageException('Failed to upload image file');
        }

        if (!move_uploaded_file($tempFilename, $uploadedFilePath)) {
            throw new ImageException('Failed to upload image file');
        }
    }

    public function renameImageFile($oldFileName, $newFileName)
    {
        $originalFilePath = $this->getUploadFolderLocation() . $this->getTaskId() . '/' . $oldFileName;
        $newFilePath = $this->getUploadFolderLocation() . $this->getTaskId() . '/' . $newFileName;

        if (!file_exists($originalFilePath)) {
            throw new ImageException('Cannot find image file to rename');
        }

        if (!rename($originalFilePath, $newFilePath)) {
            throw new ImageException('Failed to rename filename');
        }
    }

    public function deleteImageFile()
    {
        $filePath = $this->getUploadFolderLocation() . $this->getTaskId() . '/' . $this->getFilename();

        if (file_exists($filePath)) {
            if (!unlink($filePath)) {
                throw new ImageException('Failed to delete image file');
            }
        }
    }

    public function returnImageAsArray()
    {
        $image = [];
        $image['id'] = $this->getId();
        $image['task_id'] = $this->getTaskId();
        $image['title'] = $this->getTitle();
        $image['filename'] = $this->getFilename();
        $image['mime_type'] = $this->getMimeType();
        $image['image_url'] = $this->getImageUrl();

        return $image;
    }
}