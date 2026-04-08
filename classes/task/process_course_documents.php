<?php
namespace block_ai_tutor\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Tác vụ chạy nền để xử lý tài liệu của khóa học.
 * Nhiệm vụ này được kích hoạt bởi observer khi có thay đổi về nội dung.
 */
class process_course_documents extends \core\task\adhoc_task {
    /**
     * Thực thi tác vụ.
     */
    public function execute() {
        // Lấy course ID từ dữ liệu đã được truyền vào khi xếp hàng.
        $courseid = $this->get_custom_data()->courseid;

        $course = get_course($courseid);
        if (!$course) {
            mtrace("AI Tutor Task: Không tìm thấy khóa học với ID $courseid. Bỏ qua.");
            return;
        }

        mtrace("AI Tutor Task: Bắt đầu xử lý tài liệu cho khóa học '{$course->fullname}' (ID: {$courseid})...");

        $parser = new \block_ai_tutor\document_parser();
        $parser->process_and_save_chunks_for_course($course);

        mtrace("AI Tutor Task: Hoàn tất xử lý tài liệu cho khóa học ID: {$courseid}.");
    }
}
