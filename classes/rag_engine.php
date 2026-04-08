<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

class rag_engine {

    /**
     * Lấy ngữ cảnh liên quan nhất từ Database dựa trên câu hỏi
     * @param int $courseid ID khóa học hiện tại
     * @param string $query Câu hỏi của sinh viên
     * @return string Chuỗi ngữ cảnh đã được tổng hợp
     */
    public function get_context($courseid, $query) {
    global $DB;
    
    // Ép tìm trong file C02.2 nếu câu hỏi nhắc tới
    $file_filter = "";
    if (preg_match('/C02\.2/i', $query)) {
        $file_filter = " AND filename LIKE '%C02.2%'";
    }

    try {
        $sql = "SELECT id, filename, content, MATCH(content) AGAINST(?) as relevance
                FROM {block_ai_tutor_chunks} 
                WHERE courseid = ? $file_filter
                AND MATCH(content) AGAINST(?) 
                ORDER BY relevance DESC LIMIT 7";
        
        // Cần 3 tham số: $query cho SELECT, $courseid cho WHERE, $query cho WHERE
        $records = $DB->get_records_sql($sql, [$query, $courseid, $query]);

        $context = "";
        if ($records) {
            usort($records, function($a, $b) {
                return $a->id - $b->id;
            });

            foreach ($records as $record) {
                $context .= $record->content . "\n---\n";
            }
        }
        return $context;
    } catch (\Exception $e) {
        error_log("AI Tutor SQL Error: " . $e->getMessage());
        return "";
    }
}
}