<?php
namespace block_ai_tutor\task;

defined('MOODLE_INTERNAL') || die();

class regenerate_cache extends \core\task\adhoc_task {

    public function execute() {
        global $DB, $CFG;
        
        // Lấy dữ liệu tùy chỉnh được truyền từ observer
        $data = $this->get_custom_data();
        $courseid = $data->courseid ?? null;

        if (!$courseid) {
            mtrace("Lỗi: Không tìm thấy Course ID trong tác vụ Ad-hoc.");
            return;
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            mtrace("Lỗi: Khóa học ID $courseid không tồn tại.");
            return;
        }

        require_once($CFG->dirroot . '/blocks/ai_tutor/classes/document_parser.php');
        $parser = new \block_ai_tutor\document_parser();

        mtrace("--- Bắt đầu Ad-hoc Task cho khóa học: " . $course->fullname . " ---");
        
        try {
            // Chỉ cập nhật cache cho khóa học cụ thể vừa thay đổi tài liệu
            $parser->get_pdf_content_from_course($course);
            mtrace("Cập nhật cache thành công cho khóa học ID: " . $courseid);
        } catch (\Exception $e) {
            mtrace("Lỗi khi xử lý khóa học {$courseid}: " . $e->getMessage());
        }

        mtrace("--- Hoàn thành tác vụ Ad-hoc ---");
    }
}