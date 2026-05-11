<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$courseid = isset($argv[1]) ? (int)$argv[1] : 2;

cli_heading("Bắt đầu ép buộc Index tài liệu cho khóa học ID: $courseid");

$course = get_course($courseid);
if (!$course) {
    cli_writeln("Không tìm thấy khóa học với ID: $courseid");
    exit(1);
}

$parser = new \block_ai_tutor\document_parser();
try {
    cli_writeln("Đang xử lý PDF và chia chunk...");
    $parser->process_and_save_chunks_for_course($course);
    cli_writeln("Thành công!");
    
    global $DB;
    $count = $DB->count_records('block_ai_tutor_chunks', ['courseid' => $courseid]);
    cli_writeln("Tổng số chunks hiện có trong DB: $count");
} catch (\Exception $e) {
    cli_writeln("Lỗi: " . $e->getMessage());
}
