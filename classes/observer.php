<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

class observer {
    /**
     * Tự động thêm block AI Tutor khi tạo khóa học mới
     */
    public static function auto_add_ai_block(\core\event\course_created $event) {
        global $DB;
        $courseid = $event->courseid;
        $context = \context_course::instance($courseid);

        $exists = $DB->record_exists('block_instances', [
            'blockname' => 'ai_tutor',
            'parentcontextid' => $context->id
        ]);

        if (!$exists) {
            $block = new \stdClass();
            $block->blockname       = 'ai_tutor';
            $block->parentcontextid = $context->id;
            $block->showinsubcontexts = 0;
            $block->pagetypepattern   = 'course-view-*';
            $block->defaultregion     = 'side-pre';
            $block->defaultweight     = 0;
            $block->timecreated       = time();
            $block->timemodified      = time();

            $DB->insert_record('block_instances', $block);
        }
    }

    public static function handle_content_change(\core\event\base $event) {
        global $DB;

        // Cách 1: Thử lấy trực tiếp từ sự kiện
        $courseid = $event->courseid;

        // Cách 2: Nếu là sự kiện File, lấy từ context
        if (!$courseid) {
            $eventdata = $event->get_data();
            if (isset($eventdata['contextid'])) {
                $context = \context::instance_by_id($eventdata['contextid']);
                $coursecontext = $context->get_course_context(false);
                if ($coursecontext) {
                    $courseid = $coursecontext->instanceid;
                }
            }
        }

        if ($courseid) {
            // KHÔNG xóa chunks ở đây — để tránh race condition:
            // Nếu xóa ngay, sinh viên hỏi trong lúc cron chưa chạy → nhận thông báo "đang lập chỉ mục".
            // Task sẽ tự xóa chunks cũ và thay bằng chunks mới bên trong process_and_save_chunks_for_course().

            // Xóa RAG cache ngay lập tức khi tài liệu thay đổi
            // (tránh sinh viên nhận context cũ trong 10 phút TTL)
            $cache = \cache::make('block_ai_tutor', 'rag_context');
            $cache->purge();

            // Queue task với deduplicate=true: tránh chạy nhiều task song song
            // khi giảng viên upload nhiều file liên tiếp.
            $task = new \block_ai_tutor\task\process_course_documents();
            $task->set_custom_data(['courseid' => $courseid]);
            \core\task\manager::queue_adhoc_task($task, true);

            error_log("AI Tutor: Đã queue re-index task cho Course ID: " . $courseid);
        }
    }
}
