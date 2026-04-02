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
        global $CFG, $DB;

        $courseid = null;

        // 1. Tìm Course ID bằng mọi cách có thể
        if (isset($event->courseid) && $event->courseid > 0) {
            $courseid = $event->courseid;
        } else {
            // Nếu xóa module ngoài trang chính, Moodle thường để thông tin trong context hoặc snapshot
            $filedata = $event->get_record_snapshot('files', $event->objectid);
            if ($filedata) {
                $context = \context::instance_by_id($filedata->contextid);
                if ($context->contextlevel == CONTEXT_COURSE) {
                    $courseid = $context->instanceid;
                } else if ($context->contextlevel == CONTEXT_MODULE) {
                    $courseid = $DB->get_field('course_modules', 'course', ['id' => $context->instanceid]);
                }
            }
        }

        // Trường hợp đặc biệt: Xóa Course Module (Cái Long đang làm)
        if (!$courseid && strpos($event->eventname, 'course_module_deleted') !== false) {
            $courseid = $event->get_data()['courseid'] ?? null;
        }

        if ($courseid) {
            $cache_file = $CFG->dirroot . '/blocks/ai_tutor/data/cache_course_' . $courseid . '.json';

            // 2. THỰC HIỆN XÓA CACHE (Dù là Thêm hay Xóa file trên Moodle)
            if (file_exists($cache_file)) {
                // Ép xóa file bằng cách xóa cache trong bộ nhớ PHP trước
                clearstatcache(); 
                if (@unlink($cache_file)) {
                    error_log("AI Tutor: Đã xóa thành công cache Course $courseid");
                } else {
                    error_log("AI Tutor: Lỗi quyền xóa file tại $cache_file");
                }
            }

            // 3. CHỈ TẠO LẠI NẾU KHÔNG PHẢI SỰ KIỆN XÓA
            // Kiểm tra cả 'deleted' trong tên event
            if (strpos($event->eventname, 'deleted') === false) {
                $course_obj = $DB->get_record('course', array('id' => $courseid));
                $parser = new \block_ai_tutor\document_parser();
                $chunks = $parser->get_pdf_content_from_course($course_obj);
                
                if (!empty($chunks)) {
                    file_put_contents($cache_file, json_encode($chunks, JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }
}
