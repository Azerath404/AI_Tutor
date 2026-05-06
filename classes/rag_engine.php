<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

/**
 * RAG Engine: Truy xuất ngữ cảnh liên quan từ Database.
 *
 * Changelog:
 *  - [Opt E] Thêm normalize_query(): chuẩn hóa câu hỏi trước khi hash → tăng cache hit rate.
 *            Ví dụ: "Thuật toán?" và "thuật toán" → cùng cache key → không re-query SQL.
 *  - [Opt E] Xóa usort() vô nghĩa: MySQL đã sort theo relevance DESC, sort lại theo id
 *            phá vỡ thứ tự relevance và tốn CPU không cần thiết.
 */
class rag_engine {

    /**
     * [Opt E] Chuẩn hóa câu hỏi để tăng tỉ lệ cache hit.
     * Xử lý: lowercase, trim, chuẩn hóa khoảng trắng, bỏ dấu câu thừa.
     *
     * @param  string $query Câu hỏi gốc từ sinh viên.
     * @return string Câu hỏi đã chuẩn hóa.
     */
    private function normalize_query(string $query): string {
        $q = mb_strtolower(trim($query), 'UTF-8');
        // Gộp nhiều khoảng trắng thành một.
        // ?? $q: preg_replace() trả về null nếu PCRE lỗi (vd: UTF-8 không hợp lệ)
        // → fallback giữ nguyên $q thay vì để hàm trả về null (gây TypeError).
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;
        // Bỏ dấu câu, giữ lại chữ cái Unicode (\p{L}), số (\p{N}) và khoảng trắng.
        // Flag /u bắt buộc để \p{L} hoạt động — cũng là nguyên nhân null khi PCRE fail.
        $q = preg_replace('/[^\p{L}\p{N}\s]/u', '', $q) ?? $q;
        return $q;
    }

    /**
     * Lấy ngữ cảnh liên quan nhất từ Database dựa trên câu hỏi.
     *
     * Cải tiến:
     *  - LIMIT 3 thay vì 4: 4 chunks cùng file gây trùng lặp nội dung, phình prompt vô ích.
     *  - Dedup theo filename: chỉ lấy chunk tốt nhất của mỗi file (GROUP BY filename).
     *  - BOOLEAN MODE: tránh MySQL bỏ qua từ xuất hiện > 50% rows (Natural Language mode).
     *  - Dùng $normalizedQuery cho cả SQL lẫn cache key để kết quả nhất quán.
     *
     * @param  int    $courseid ID khóa học hiện tại.
     * @param  string $query    Câu hỏi của sinh viên.
     * @return string Chuỗi ngữ cảnh đã được tổng hợp.
     */
    public function get_context($courseid, $query) {
        global $DB;

        // Normalize trước khi hash VÀ trước khi đưa vào SQL
        // → "Thuật toán?" và "thuật toán" → cùng cache key + cùng kết quả SQL
        $normalizedQuery = $this->normalize_query($query);
        $cache    = \cache::make('block_ai_tutor', 'rag_context');
        $cacheKey = $courseid . '_' . md5($normalizedQuery);

        $cached = $cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        try {
            // Lấy chunk có relevance cao nhất cho mỗi file (dedup theo filename).
            // Tại sao dedup? Chunking có overlap → nhiều chunk cùng file → nội dung trùng nhau
            // → prompt phình to, tốn prefill time, không thêm thông tin mới cho AI.
            // BOOLEAN MODE: tránh MySQL tự loại từ xuất hiện > 50% rows.
            $sql = "SELECT filename, content,
                           MAX(MATCH(content) AGAINST(? IN BOOLEAN MODE)) as relevance
                    FROM {block_ai_tutor_chunks}
                    WHERE courseid = ?
                      AND MATCH(content) AGAINST(? IN BOOLEAN MODE)
                    GROUP BY filename
                    ORDER BY relevance DESC
                    LIMIT 3";

            $records = $DB->get_records_sql($sql, [$normalizedQuery, $courseid, $normalizedQuery]);

            if (empty($records)) {
                // Fallback: FULLTEXT không khớp → lấy 2 chunk bất kỳ để AI không trả lời rỗng
                $fallback = $DB->get_records_sql(
                    "SELECT filename, content FROM {block_ai_tutor_chunks}
                     WHERE courseid = ? GROUP BY filename LIMIT 2",
                    [$courseid]
                );
                $records = $fallback;
            }

            $context = "";
            foreach ($records as $record) {
                $context .= $record->content . "\n---\n";
            }

            $cache->set($cacheKey, $context);
            return $context;

        } catch (\Exception $e) {
            error_log("AI Tutor RAG Error: " . $e->getMessage());
            return "";
        }
    }

}