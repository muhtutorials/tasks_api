<?php

class TaskException extends Exception { }

class Task
{
    private $_id;
    private $_title;
    private $_description;
    private $_deadline;
    private $_completed;
    private $_images;

    public function __construct($id, $title, $description, $deadline, $completed, $_images = [])
    {
        $this->setId($id);
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setDeadline($deadline);
        $this->setCompleted($completed);
        $this->setImages($_images);
    }

    public function getId()
    {
        return $this->_id;
    }

    public function getTitle()
    {
        return $this->_title;
    }

    public function getDescription()
    {
        return $this->_description;
    }

    public function getDeadline()
    {
        return $this->_deadline;
    }

    public function getCompleted()
    {
        return $this->_completed;
    }

    public function getImages()
    {
        return $this->_images;
    }

    public function setId($id)
    {
        // 9223372036854775807 is the maximum value for the MySQL big integer datatype
        if ($id !== null && (!is_numeric($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null)) {
            throw new TaskException('Task ID error');
        }

        $this->_id = $id;
    }

    public function setTitle($title)
    {
        if (strlen($title) < 1 || strlen($title) > 255) {
            throw new TaskException('Task title error');
        }

        $this->_title = $title;
    }

    public function setDescription($description)
    {
        // 16777215 is the maximum value for the MySQL mediumtext datatype
        if ($description !== null && strlen($description  > 16777215)) {
            throw new TaskException('Task description error');
        }

        $this->_description = $description;
    }

    public function setDeadline($deadline)
    {
        // convert the passed in time string to a DateTime object and back
        // to check if the passed in time string was converted correctly
        if ($deadline !== null && date_format(date_create_from_format('d/m/Y H:i', $deadline), 'd/m/Y H:i') != $deadline) {
            throw new TaskException('Task deadline datetime error');
        }

        $this->_deadline = $deadline;
    }

    public function setCompleted($completed)
    {
        if (strtoupper($completed) !== 'Y' && strtoupper($completed) !== 'N') {
            throw new TaskException('Task completed must be a Y or an N');
        }

        $this->_completed = $completed;
    }

    public function setImages($images)
    {
        if (!is_array($images)) throw new TaskException('Images is not an array');

        $this->_images = $images;
    }

    public function returnTaskAsArray()
    {
        $task = [];
        $task['id'] = $this->getId();
        $task['title'] = $this->getTitle();
        $task['description'] = $this->getDescription();
        $task['deadline'] = $this->getDeadline();
        $task['completed'] = $this->getCompleted();
        $task['images'] = $this->getImages();

        return $task;
    }
}
