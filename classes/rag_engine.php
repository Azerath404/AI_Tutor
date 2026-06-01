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
     *  - Metadata Boosting: Bóc tách tên file từ câu hỏi và cộng điểm (boost) ưu tiên trong SQL.
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
        $cacheKey = 'v3_' . $courseid . '_' . md5($normalizedQuery);

        $cached = $cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        try {
            // Metadata Boosting: Bóc tách tên file từ câu hỏi để cộng điểm ưu tiên
            $boost_sql = "";
            $boost_params = [];
            
            // Regex nhận diện tên file có đuôi phổ biến (hỗ trợ Unicode tiếng Việt)
            if (preg_match_all('/([\p{L}\p{N}_\-]+\.(?:pdf|docx?|pptx?|xlsx?|txt|md|csv))/ui', $query, $matches)) {
                $mentioned_files = array_unique($matches[1]);
                $boost_conditions = [];
                foreach ($mentioned_files as $file) {
                    $boost_conditions[] = "LOWER(filename) LIKE ?";
                    $boost_params[] = '%' . mb_strtolower($file, 'UTF-8') . '%';
                }
                if (!empty($boost_conditions)) {
                    $boost_sql = " + IF(" . implode(" OR ", $boost_conditions) . ", 5.0, 0.0)";
                }
            }

            // Tìm danh sách khóa học liên quan (bao gồm cả hiện tại và môn học tiên quyết)
            $course_ids = [$courseid];
            $prereqs = $DB->get_records('block_ai_tutor_course_deps', ['course_id' => $courseid]);
            if (!empty($prereqs)) {
                foreach ($prereqs as $prereq) {
                    $course_ids[] = (int)$prereq->prerequisite_id;
                }
            }
            $course_ids = array_unique($course_ids);
            list($insql, $inparams) = $DB->get_in_or_equal($course_ids);

            // Lấy các chunks có relevance tốt nhất trước (không GROUP BY trong SQL)
            // Giải quyết triệt để lỗi ONLY_FULL_GROUP_BY của MySQL và lấy đúng chunk khớp nhất.
            $sql = "SELECT id, filename, content,
                           (MATCH(content) AGAINST(? IN BOOLEAN MODE){$boost_sql}) as relevance
                    FROM {block_ai_tutor_chunks}
                    WHERE courseid {$insql}
                      AND MATCH(content) AGAINST(? IN BOOLEAN MODE)
                    ORDER BY relevance DESC
                    LIMIT 40";

            $params = array_merge([$normalizedQuery], $boost_params, $inparams, [$normalizedQuery]);
            $all_records = $DB->get_records_sql($sql, $params);

            if (empty($all_records)) {
                // Fallback: FULLTEXT không khớp → lấy 2 chunk bất kỳ để AI không trả lời rỗng
                $fallback = $DB->get_records_sql(
                    "SELECT id, filename, content FROM {block_ai_tutor_chunks}
                     WHERE courseid {$insql} LIMIT 2",
                    $inparams
                );
                $records = $fallback;
            } else {
                // Thực hiện deduplicate theo filename trong PHP
                $records = [];
                foreach ($all_records as $record) {
                    if (!isset($records[$record->filename])) {
                        $records[$record->filename] = $record;
                    }
                    if (count($records) >= 15) {
                        break;
                    }
                }

                // [Opt] PHP Reranking: Chấm điểm lại 15 chunk từ MySQL bằng TF (Term Frequency) nội bộ
                $scored_records = [];
                $keywords = array_filter(explode(' ', $normalizedQuery));
                
                foreach ($records as $record) {
                    $score = (float)$record->relevance;
                    $content_lower = mb_strtolower($record->content, 'UTF-8');
                    
                    foreach ($keywords as $kw) {
                        if (mb_strlen($kw, 'UTF-8') > 2) {
                            $score += substr_count($content_lower, $kw) * 0.5;
                        }
                    }
                    $record->final_score = $score;
                    $scored_records[] = $record;
                }
                
                // Sắp xếp lại theo điểm mới
                usort($scored_records, function($a, $b) {
                    return $b->final_score <=> $a->final_score;
                });
                
                // Chỉ lấy 3 chunk tốt nhất để nhét vào prompt, giữ context ngắn gọn cho Intel Iris Xe
                $records = array_slice($scored_records, 0, 3);
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