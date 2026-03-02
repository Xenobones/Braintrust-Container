-- Creates the dev_projects database for AI-created project DBs.
-- This runs after schema.sql during MySQL container first-boot init.
-- braintrust_user (created by MYSQL_USER env var) gets access to both databases.

CREATE DATABASE IF NOT EXISTS `dev_projects`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

-- Grant braintrust_user access to dev_projects in addition to braintrust_ide
GRANT ALL PRIVILEGES ON `dev_projects`.* TO 'braintrust_user'@'%';
FLUSH PRIVILEGES;
