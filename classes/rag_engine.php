<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

/**
 * Module RAG (Retrieval-Augmented Generation) - Thuật toán tìm kiếm ngữ cảnh tối ưu
 */
class rag_engine {

    /**
     * Tìm và trả về các đoạn văn bản liên quan nhất từ mảng chunks đã băm sẵn
     */
    public function retrieve_relevant_context($question, $recent_history, $chunks) {
        // Kiểm tra dữ liệu đầu vào (chunks bây giờ là một mảng Array từ document_parser)
        if (empty($chunks) || !is_array($chunks)) {
            return "";
        }

        // 1. Xử lý từ khóa tìm kiếm
        $search_query = $question;
        
        // Móc nối ngữ cảnh từ lịch sử chat để hiểu các câu hỏi như "còn file đó thì sao?"
        if (!empty($recent_history)) {
            foreach ($recent_history as $log) {
                if ($log->role === 'user') {
                    $search_query .= " " . $log->message;
                }
            }
        }

        // 2. Lọc Stop Words & Chuẩn hóa từ khóa
        $stop_words = ['có', 'không', 'là', 'gì', 'của', 'về', 'việc', 'các', 'một', 'nội', 'dung', 'nào', 'sao', 'ai', 'cho', 'trong', 'những', 'tất', 'cả', 'bài', 'tập', 'còn', 'thì', 'đâu', 'như', 'thế', 'này', 'tôi', 'bạn', 'file', 'số', 'xin', 'hãy', 'đó', 'tài', 'liệu', 'tóm', 'tắt'];
        
        $clean_question = str_replace(['?', '.', ',', '!', ':', '"', '\''], '', mb_strtolower($search_query));
        $words = explode(' ', $clean_question);
        
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            // Lấy từ khóa >= 2 ký tự hoặc là con số, chữ cái đơn lẻ
            if (!in_array($word, $stop_words) && (mb_strlen($word) >= 2 || is_numeric($word))) {
                $keywords[] = $word;
            }
        }
        $keywords = array_unique($keywords);

        // 3. CHẤM ĐIỂM (SCORING) TRÊN MẢNG CHUNKS
        $scored_chunks = [];
        foreach ($chunks as $chunk_data) {
            $score = 0;
            // Chunks bây giờ có cấu trúc: ['file' => 'tên.pdf', 'content' => 'nội dung...']
            $chunk_text = $chunk_data['content'];
            $file_name = $chunk_data['file'];

            foreach ($keywords as $kw) {
                // Ưu tiên cực cao nếu khớp tên file (Ví dụ sinh viên hỏi đúng tên Lab)
                if (mb_stripos($file_name, $kw) !== false) {
                    $score += 5; 
                }

                // Chấm điểm nội dung
                if (mb_strlen($kw) <= 2) {
                    // Dùng ranh giới từ để tránh khớp nhầm chữ 'g' trong 'trong'
                    if (preg_match('/\b' . preg_quote($kw, '/') . '\b/ui', $chunk_text)) {
                        $score += 2; 
                    }
                } else {
                    if (mb_stripos($chunk_text, $kw) !== false) {
                        $score++;
                    }
                }
            }

            if ($score > 0) {
                $scored_chunks[] = ['text' => $chunk_text, 'score' => $score];
            }
        }

        // 4. Ranking (Xếp hạng)
        usort($scored_chunks, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // 5. Tổng hợp kết quả (Giới hạn 4500 ký tự để AI phản hồi nhanh)
        $relevant_text = "";
        $char_count = 0;
        foreach ($scored_chunks as $item) {
            $relevant_text .= $item['text'] . "\n...\n"; 
            $char_count += mb_strlen($item['text']);
            if ($char_count > 4500) break; 
        }

        // Nếu không tìm thấy gì liên quan, lấy 1500 chữ đầu của mảng chunks để AI có cái trả lời
        if (empty($relevant_text)) {
            $first_chunk = reset($chunks);
            return isset($first_chunk['content']) ? mb_substr($first_chunk['content'], 0, 1500) : "";
        }

        return $relevant_text;
    }
}