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

    /**
     * Lưu tin nhắn vào database
     */
    public function save_chat_log($userId, $courseId, $role, $message){
        global $DB;
        $record = new \stdClass();
        $record->userId = $userId;
        $record->courseId = $courseId;
        $record->role = $role;
        $record->message = $message;
        $record->timecreated = time();

        return $DB->insert_record('block_ai_tutor_logs', $record);
    }

    /**
     * Lấy N tin nhắn gần nhất của Sinh viên trong khóa học này
     */
    public function get_chat_history($userId, $courseId, $limit = 5){
        global $DB;
        // Lấy $limit tin nhắn mới nhất, sắp xếp theo thời gian
        $query = "SELECT * FROM {block_ai_tutor_logs} 
                WHERE userid = ? AND courseid = ? 
                ORDER BY timecreated DESC, id DESC";
        $records = $DB->get_records_sql($query, [$userId, $courseId], 0, $limit);
        if ($records){
            return array_reverse($records);
        }
        return [];
    }

    /**
     * Xóa lịch sử chat của một người dùng trong một khóa học cụ thể
     */
    public function delete_chat_history($userId, $courseId) {
        global $DB;
        return $DB->delete_records('block_ai_tutor_logs', [
            'userid' => $userId, 
            'courseid' => $courseId
        ]);
    }

    /**
     * TỰ ĐỘNG: Xóa các tin nhắn cũ hơn N ngày (Dùng cho bảo trì hệ thống)
     */
    public function auto_purge_old_logs($days = 7) {
        global $DB;
        $cutOffTime = time() - ($days * 24 * 60 * 60);
        $sql = "timecreated < ?";
        return $DB->delete_records_select('block_ai_tutor_logs', $sql, [$cutOffTime]);
    }
}