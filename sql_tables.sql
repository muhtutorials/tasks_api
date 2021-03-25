CREATE DATABASE `tasks_api`;

CREATE TABLE `users` (
    `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
    `full_name` VARCHAR(255) NOT NULL,
    `username` VARCHAR(255) UNIQUE NOT NULL,
    `password` VARCHAR(255) COLLATE utf8_bin NOT NULL,
    `is_active` ENUM('Y', 'N') DEFAULT 'Y' NOT NULL,
    `login_attempts` INT(1) DEFAULT 0 NOT NULL
);

CREATE TABLE `sessions` (
    `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
    `user_id` BIGINT NOT NULL,
    `access_token` VARCHAR(100) UNIQUE COLLATE 'utf8_bin' NOT NULL,
    `access_token_expiration` DATETIME NOT NULL,
    `refresh_token` VARCHAR(100) UNIQUE COLLATE 'utf8_bin' NOT NULL,
    `refresh_token_expiration` DATETIME NOT NULL,
    CONSTRAINT `fk_sessions_users`
    FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
);

CREATE TABLE `tasks` (
    `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
    `user_id` BIGINT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` MEDIUMTEXT,
    `deadline` DATETIME,
    `completed` ENUM('Y', 'N') DEFAULT 'N' NOT NULL,
    CONSTRAINT `fk_tasks_users`
    FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
);

CREATE TABLE `images` (
    `id` BIGINT PRIMARY KEY AUTO_INCREMENT,
    `task_id` BIGINT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `filename` VARCHAR(30) NOT NULL,
    `mime_type` VARCHAR(255) NOT NULL,
    CONSTRAINT `fk_images_tasks`
    FOREIGN KEY (`task_id`)
        REFERENCES `tasks`(`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,
    CONSTRAINT `uq_task_id_filename`
    UNIQUE(`task_id`, `filename`)
);
