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
        // Bước 1: Xóa dữ liệu cũ của môn này trong DB để tránh chồng chéo
        $DB->delete_records('block_ai_tutor_chunks', ['courseid' => $courseid]);

        // Bước 2: Tạo Adhoc Task
        $task = new \block_ai_tutor\task\process_course_documents();
        $task->set_custom_data(['courseid' => $courseid]);
        
        \core\task\manager::queue_adhoc_task($task);
        
        error_log("AI Tutor: Đã tạo Adhoc Task cho Course ID: " . $courseid);
    }
}
}
