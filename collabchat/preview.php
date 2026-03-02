<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    die('Access denied');
}

$project = $_GET['project'] ?? '';
$file = $_GET['file'] ?? '';

// Sanitize
$project = preg_replace('/[^a-zA-Z0-9_-]/', '', $project);
$file = basename($file);

$filePath = "/var/www/html/collabchat/projects/{$project}/{$file}";

if (!file_exists($filePath)) {
    die('File not found');
}

// Serve the HTML file
header('Content-Type: text/html');
readfile($filePath);
?>