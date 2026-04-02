<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

class rag_engine {

    public function retrieve_relevant_context($question, $recent_history, $chunks) {
        if (empty($chunks) || !is_array($chunks)) {
            return "";
        }

        // 1. Tổng hợp truy vấn và mở rộng từ khóa liên kết (Query Expansion)
        $search_query = mb_strtolower($question);
        if (!empty($recent_history)) {
            foreach (array_slice($recent_history, -2) as $log) {
                if ($log->role === 'user') {
                    $search_query .= " " . mb_strtolower($log->message);
                }
            }
        }

        // 2. Lọc Stop Words & Chuẩn hóa từ khóa
        $stop_words = ['có', 'không', 'là', 'gì', 'của', 'về', 'việc', 'các', 'một', 'nội', 'dung', 'nào', 'sao', 'ai', 'cho', 'trong', 'những', 'tất', 'cả', 'tập', 'còn', 'thì', 'đâu', 'như', 'thế', 'này', 'tôi', 'bạn', 'xin', 'hãy', 'đó', 'tài', 'liệu', 'tóm', 'tắt', 'giữa', 'liên', 'hệ', 'khác', 'nhau'];
        
        $clean_query = str_replace(['?', '.', ',', '!', ':', '"', '\''], '', $search_query);
        $words = explode(' ', $clean_query);
        
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            $pure_word = rtrim($word, '()');
            if (!in_array($pure_word, $stop_words) && (mb_strlen($pure_word) >= 2 || is_numeric($pure_word))) {
                $keywords[] = $pure_word;
            }
        }

        // Tự động thêm từ khóa bổ trợ để tăng tính tổng quát khi hỏi về mối liên hệ
        if (strpos($search_query, 'liên hệ') !== false || strpos($search_query, 'khác nhau') !== false) {
            $keywords[] = 'đối tượng';
            $keywords[] = 'cấu trúc';
            $keywords[] = 'phương thức';
            $keywords[] = 'hàm';
        }
        $keywords = array_unique($keywords);
        $folder_keywords = ['bổ trợ', 'tham khảo', 'bài giảng', 'lab'];
        $target_folder = '';
        foreach ($folder_keywords as $fk) {
            if (mb_stripos($search_query, $fk) !== false) {
                $target_folder = $fk;
                break;
            }
        }
        // 3. CHẤM ĐIỂM (SCORING) - THUẬT TOÁN TỔNG QUÁT HÓA
        $scored_chunks = [];
        foreach ($chunks as $chunk_data) {
            $chunk_text = $chunk_data['content'] ?? '';
            if (empty($chunk_text)) continue;

            $score = 0;
            $file_name = mb_strtolower($chunk_data['file']);
            $chunk_lower = mb_strtolower($chunk_text);
            foreach ($keywords as $kw) {
                if (!empty($target_folder) && mb_stripos($file_name, $target_folder) !== false) {
                    $score += 500.0; // Điểm thưởng cực cao để ép AI chỉ lấy file này
                }
                // A. Khớp trong tên file (Trọng số cao cho tính liên quan trực tiếp)
                if (mb_stripos($file_name, $kw) !== false) {
                    $score += 40.0; 
                }

                // B. Khớp trong nội dung (Diversity Boost)
                $count = mb_substr_count($chunk_lower, $kw);
                if ($count > 0) {
                    $score += 30.0; // Điểm thưởng vì CÓ xuất hiện từ khóa (Quan trọng hơn tần suất)
                    $score += (min($count, 3) * 5); // Tối đa 15 điểm cho tần suất để tránh "spam" từ khóa
                }

                // C. Nhận diện cấu trúc kỹ thuật (Hàm/Lớp)
                if (preg_match('/' . preg_quote($kw, '/') . '\s*\(/i', $chunk_lower)) {
                    $score += 60.0; 
                }
            }

            if ($score > 0) {
                // Lưu thêm metadata nguồn để AI dễ trích dẫn
                $scored_chunks[] = [
                    'text' => "[Nguồn: " . $chunk_data['file'] . "]\n" . $chunk_text, 
                    'score' => $score
                ];
            }
        }

        // 4. Ranking
        usort($scored_chunks, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // 5. Tổng hợp kết quả (Tăng độ rộng Context)
        $relevant_text = "";
        $char_count = 0;
        $max_chars = 5000; 

        // Lấy Top 12 đoạn để tăng khả năng xuất hiện đủ cả 2 môn
        foreach (array_slice($scored_chunks, 0, 6) as $item) {
            $item_len = mb_strlen($item['text']);
            if ($char_count > 0 && ($char_count + $item_len) > $max_chars) {
                break;
            }
            $relevant_text .= $item['text'] . "\n---\n";
            $char_count += $item_len;
        }

        return $relevant_text ?: "Không tìm thấy nội dung phù hợp trong tài liệu hệ thống.";
    }

    public function get_combined_chunks($current_course_id) {
        global $DB;
        $all_chunks = [];

        $prereq_ids = $DB->get_fieldset_select('block_ai_tutor_course_deps', 'prerequisite_id', 'course_id = ?', [$current_course_id]);
        $course_ids = array_merge([$current_course_id], $prereq_ids);

        foreach ($course_ids as $id) {
            $cache_file = __DIR__ . '/../data/cache_course_' . $id . '.json';
            
            if (file_exists($cache_file)) {
                $json_data = file_get_contents($cache_file);
                $course_chunks = json_decode($json_data, true);
                if (is_array($course_chunks)) {
                    $all_chunks = array_merge($all_chunks, $course_chunks);
                }
            } else {
                // NẾU KHÔNG CÓ CACHE (Do vừa bị xóa hoặc chưa có)
                $course_obj = $DB->get_record('course', ['id' => $id]);
                if ($course_obj) {
                    // Gọi Parser để quét lại toàn bộ file hiện có trong khóa học
                    $parser = new \block_ai_tutor\document_parser();
                    $new_chunks = $parser->get_pdf_content_from_course($course_obj);
                    
                    if (!empty($new_chunks)) {
                        $all_chunks = array_merge($all_chunks, $new_chunks);
                        // Lưu lại cache mới để các lần hỏi sau chạy nhanh hơn
                        file_put_contents($cache_file, json_encode($new_chunks, JSON_UNESCAPED_UNICODE));
                    }
                }
            }
        }
        return $all_chunks; 
    }
}