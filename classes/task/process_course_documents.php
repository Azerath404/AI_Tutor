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
        $data     = $this->get_custom_data();
        $courseid = (int)($data->courseid ?? 0);

        if (!$courseid) {
            mtrace("AI Tutor Task: Không có courseid trong custom_data. Bỏ qua.");
            return;
        }

        try {
            $course = get_course($courseid);
            if (!$course) {
                mtrace("AI Tutor Task: Không tìm thấy khóa học ID $courseid. Bỏ qua.");
                return;
            }

            mtrace("AI Tutor Task: Bắt đầu xử lý '{$course->fullname}' (ID: {$courseid})...");

            $parser = new \block_ai_tutor\document_parser();
            $parser->process_and_save_chunks_for_course($course);

            mtrace("AI Tutor Task: Hoàn tất xử lý tài liệu cho khóa học ID: {$courseid}.");

        } catch (\Throwable $e) {
            // Ghi lỗi nhưng KHÔNG re-throw:
            // Re-throw khiến Moodle retry task 3 lần liên tiếp (tốn CPU).
            // Lỗi thường do file PDF hỏng hoặc quyền OCR — retry không giúp gì.
            mtrace("AI Tutor Task ERROR [{$courseid}]: " . $e->getMessage()
                 . " tại " . $e->getFile() . " dòng " . $e->getLine());
            error_log("AI Tutor Task ERROR [{$courseid}]: " . $e->getMessage());
        }
    }
}
