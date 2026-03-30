<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

class observer {
    /**
     * Lên lịch tác vụ ad-hoc để tạo lại cache ngay lập tức.
     *
     * @param \core\event\base $event
     */
    public static function schedule_cache_regeneration(\core\event\base $event) {
        // Lấy thông tin module từ sự kiện
        $other = $event->get_data()['other'] ?? [];
        $modname = $event->other['modulename'] ?? $event->objectname;

        // Danh sách các module chứa tài liệu cần cache
        $allowed_modules = ['resource', 'folder'];

        if (!in_array($modname, $allowed_modules)) {
            return;
        }

        $courseid = $event->courseid;
        if (!$courseid) {
            return;
        }

        // Tạo instance của adhoc_task
        $task = new \block_ai_tutor\task\regenerate_cache();
        
        // Truyền Course ID vào task để nó chỉ xử lý đúng khóa học đó
        $task->set_custom_data(['courseid' => $courseid]);
        
        // Đưa vào hàng đợi để Moodle Cron xử lý ngầm sớm nhất có thể
        \core\task\manager::queue_adhoc_task($task);
    }
}
