<?php
define('CLI_SCRIPT', true); // Khai báo đây là script chạy bằng dòng lệnh

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

cli_heading("Bắt đầu quy trình tự động thêm Block AI Tutor vào tất cả khóa học...");

// 1. Lấy danh sách tất cả các khóa học (loại trừ trang chủ ID = 1)
$courses = $DB->get_records_select('course', 'id > 1');

$count_added = 0;
$count_skipped = 0;

foreach ($courses as $course) {
    // 2. Lấy context của khóa học
    $context = context_course::instance($course->id);

    // 3. Kiểm tra xem block ai_tutor đã tồn tại trong khóa học này chưa
    $exists = $DB->record_exists('block_instances', [
        'blockname' => 'ai_tutor',
        'parentcontextid' => $context->id
    ]);

    if (!$exists) {
        // 4. Chuẩn bị dữ liệu để chèn vào bảng mdl_block_instances
        $block = new stdClass();
        $block->blockname       = 'ai_tutor';
        $block->parentcontextid = $context->id;
        $block->showinsubcontexts = 0;
        $block->pagetypepattern   = 'course-view-*';
        $block->defaultregion     = 'side-pre';
        $block->defaultweight     = 0;
        $block->timecreated       = time();
        $block->timemodified      = time();

        $DB->insert_record('block_instances', $block);
        
        mtrace(" [+] Đã thêm AI Tutor vào khóa học: {$course->fullname}");
        $count_added++;
    } else {
        mtrace(" [!] Bỏ qua: Khóa học '{$course->fullname}' đã có sẵn block.");
        $count_skipped++;
    }
}

cli_separator();
mtrace("HOÀN TẤT!");
mtrace(" - Số khóa học đã thêm mới: $count_added");
mtrace(" - Số khóa học đã có sẵn: $count_skipped");