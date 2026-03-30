<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

class rag_engine {

    public function retrieve_relevant_context($question, $recent_history, $chunks) {
        if (empty($chunks) || !is_array($chunks)) {
            return "";
        }

        // 1. Tổng hợp truy vấn từ lịch sử để giữ ngữ cảnh
        $search_query = $question;
        if (!empty($recent_history)) {
            foreach (array_slice($recent_history, -2) as $log) { // Chỉ lấy 2 câu gần nhất để tránh loãng
                if ($log->role === 'user') {
                    $search_query .= " " . $log->message;
                }
            }
        }

        // 2. Lọc Stop Words & Trích xuất từ khóa chuyên sâu
        $stop_words = ['có', 'không', 'là', 'gì', 'của', 'về', 'việc', 'các', 'một', 'nội', 'dung', 'nào', 'sao', 'ai', 'cho', 'trong', 'những', 'tất', 'cả', 'tập', 'còn', 'thì', 'đâu', 'như', 'thế', 'này', 'tôi', 'bạn', 'xin', 'hãy', 'đó', 'tài', 'liệu', 'tóm', 'tắt'];
        
        // Giữ lại dấu ngoặc () trong quá trình làm sạch để nhận diện hàm
        $clean_query = str_replace(['?', '.', ',', '!', ':', '"', '\''], '', mb_strtolower($search_query));
        $words = explode(' ', $clean_query);
        
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            // Loại bỏ dấu ngoặc ở cuối để lấy tên hàm thuần túy làm từ khóa
            $pure_word = rtrim($word, '()');
            if (!in_array($pure_word, $stop_words) && (mb_strlen($pure_word) >= 2 || is_numeric($pure_word))) {
                $keywords[] = $pure_word;
            }
        }
        $keywords = array_unique($keywords);

        // --- TRÍCH XUẤT SỐ CHƯƠNG (Metadata Boosting) ---
        $target_number = null;
        if (preg_match('/(?:file|bài|chương)\s*(\d+)/i', mb_strtolower($search_query), $matches)) {
            $target_number = $matches[1];
        }

        // 3. CHẤM ĐIỂM (SCORING) VỚI TRỌNG SỐ MỚI
        $scored_chunks = [];
        foreach ($chunks as $chunk_data) {
            $chunk_text = $chunk_data['content'] ?? '';
            if (empty($chunk_text)) continue;

            $score = 0;
            $file_name = mb_strtolower($chunk_data['file']);
            $chunk_lower = mb_strtolower($chunk_text);

            // BƯỚC A: SIÊU ƯU TIÊN THEO SỐ FILE (Quan trọng cho kịch bản 1)
            if ($target_number !== null && strpos($file_name, $target_number) !== false) {
                $score += 250.0; // Tăng vọt lên 250 điểm để ép đoạn này vào Top 1
            }

            foreach ($keywords as $kw) {
                // BƯỚC B: BOOSTING TỪ KHÓA TRONG TÊN FILE
                if (mb_stripos($file_name, $kw) !== false) {
                    $score += 50.0; 
                }

                // BƯỚC C: CHẤM ĐIỂM NỘI DUNG
                $count = mb_substr_count($chunk_lower, $kw);
                if ($count > 0) {
                    $score += 20; // Điểm cơ bản khi khớp từ khóa
                    $score += ($count * 2); 
                }

                // BƯỚC D: NHẬN DIỆN CẤU TRÚC HÀM (FIX LỖI AI KHÔNG THẤY HÀM SOUND)
                // Regex tìm kiếm "kw(" hoặc "def kw"
                if (preg_match('/' . preg_quote($kw, '/') . '\s*\(/i', $chunk_lower) || 
                    preg_match('/def\s+' . preg_quote($kw, '/') . '/i', $chunk_lower)) {
                    $score += 100.0; // Thưởng cực cao cho các đoạn chứa định nghĩa/gọi hàm
                }
            }

            if ($score > 0) {
                $scored_chunks[] = ['text' => $chunk_text, 'score' => $score];
            }
        }

        // 4. Ranking (Sắp xếp)
        usort($scored_chunks, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // 5. Tổng hợp kết quả (Tối ưu cửa sổ ngữ cảnh)
        $relevant_text = "";
        $char_count = 0;
        $max_chars = 2500; // Giới hạn tổng ký tự gửi đi để không làm tràn Token của Llama

        // Lấy Top 10 đoạn chất lượng nhất
        foreach (array_slice($scored_chunks, 0, 10) as $item) {
            $item_len = mb_strlen($item['text']);
            // Chỉ thêm chunk mới nếu nó không làm tổng độ dài vượt quá giới hạn
            if ($char_count > 0 && ($char_count + $item_len) > $max_chars) {
                break;
            }
            $relevant_text .= $item['text'] . "\n---\n";
            $char_count += $item_len;
        }

        return $relevant_text ?: "Không tìm thấy nội dung phù hợp trong tài liệu bài giảng.";
    }

    public function get_combined_chunks($current_course_id) {
        global $DB;
        $all_chunks = [];

        // 1. Tìm các môn tiên quyết của môn này trong DB
        $prereq_ids = $DB->get_fieldset_select('block_ai_tutor_course_deps', 'prerequisite_id', 'course_id = ?', [$current_course_id]);
        
        // 2. Gom môn hiện tại và các môn tiên quyết vào 1 danh sách
        $course_list = array_merge([$current_course_id], $prereq_ids);

        // 3. Đọc file JSON Cache của tất cả các môn này
        foreach ($course_list as $id) {
            $cache_file = __DIR__ . '/data/cache_course_' . $id . '.json';
            if (file_exists($cache_file)) {
                error_log("AI Tutor nạp kiến thức từ Course ID: " . $id);
                $course_obj = $DB->get_record('course', ['id' => $id]);
                $parser = new \block_ai_tutor\document_parser();
                $chunks = $parser->get_pdf_content_from_course($course_obj);
            } else $chunks = json_decode(file_get_contents($cache_file), true);
            if (!empty($course)) {
                $all_chunks = array_merge($all_chunks, $course);
            }
            return $all_chunks;
        }
    }
}