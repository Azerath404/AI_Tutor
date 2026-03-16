<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

/**
 * Repository Layer: Chuyên trách truy xuất dữ liệu từ Moodle Database
 */
class repository {

    /**
     * Lấy thông tin khóa học theo ID
     */
    public function get_course($course_id) {
        global $DB;
        return $DB->get_record('course', ['id' => $course_id]);
    }

    /**
     * Lấy danh sách các hoạt động (Activities) trong khóa học để làm ngữ cảnh
     */
    public function get_course_activities_summary($course) {
        // Sử dụng hàm modinfo của Moodle để lấy cache nhanh
        $modinfo = get_fast_modinfo($course);
        $activity_list = [];
        
        foreach ($modinfo->cms as $cm) {
            // Chỉ lấy những module sinh viên có thể thấy
            if ($cm->uservisible) {
                // Format: - Tên bài (Loại module)
                $activity_list[] = "- " . $cm->name . " (Loại: " . $cm->modname . ")";
            }
        }
        
        return $activity_list;
    }
}
