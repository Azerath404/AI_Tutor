<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/repository.php');

// Kiểm tra đăng nhập
require_login();

$courseId = required_param('course_id', PARAM_INT);
$userId = $USER->id;

$repo = new \block_ai_tutor\repository();
$result = $repo->delete_chat_history($userId, $courseId);

header('Content-Type: application/json');
echo json_encode(['success' => $result]);