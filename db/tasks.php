<?php
defined('MOODLE_INTERNAL') || die();

// Ad-hoc tasks (như process_course_documents) không cần được khai báo tại đây.
// File này chỉ dành cho các tác vụ định kỳ (Scheduled Tasks) chạy theo lịch trình cron.
$tasks = [];
