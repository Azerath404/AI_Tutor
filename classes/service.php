<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

/**
 * Service Layer: Xử lý logic nghiệp vụ và giao tiếp AI
 */
class service {

    private $api_key;
    private $model;
    private $temperature;
    private $max_tokens;
    private $repo;
    private $client;
    private $server_url;

    public function __construct() {
        $this->server_url = get_config('block_ai_tutor', 'ollama_url') ?: 'http://localhost:11434';
        $this->model = get_config('block_ai_tutor', 'ollama_model') ?: 'llama3.2';
        $this->temperature = (float)get_config('block_ai_tutor', 'ollama_temperature') ?: 0.7;
        $this->max_tokens = (int)get_config('block_ai_tutor', 'ollama_maxtokens') ?: 1000;
        
        $this->repo = new repository();
        $this->client = new llm_client($this->server_url, $this->model);
    }

    public function get_repo() {
        return $this->repo;
    }

    /**
     * Xây dựng ngữ cảnh (Context)
     */
    public function build_context_prompt($course_id, $user, $question = "") {
        $context_prompt = "";

        if ($course_id > 1) {
            $course = $this->repo->get_course($course_id);
            
            if ($course) {
                $course_summary = strip_tags($course->summary);
                $context_prompt = "Bạn là Trợ lý AI (AI Tutor) riêng của môn học: '{$course->fullname}'.\n";
                $context_prompt .= "Mô tả môn học: {$course_summary}\n";

                $activity_list = $this->repo->get_course_activities_summary($course);
                if (!empty($activity_list)) {
                    $context_prompt .= "Danh sách tài liệu/bài tập:\n" . implode("\n", $activity_list) . "\n";
                }
                
                $pdf_text = $this->repo->get_pdf_content_from_course($course);
                
                if (!empty($pdf_text)) {
                    $relevant_text = "";
                    
                    if (!empty($question)) {
                        // FIX LỖI 2: Móc nối câu hỏi ngay trước đó của User để giữ Ngữ cảnh tìm kiếm
                        $search_query = $question;
                        $recent_history = $this->repo->get_chat_history($user->id, $course_id, 2);
                        if (!empty($recent_history)) {
                            foreach ($recent_history as $log) {
                                if ($log->role === 'user') {
                                    $search_query .= " " . $log->message; // Ghép câu cũ vào câu mới
                                }
                            }
                        }

                        $stop_words = ['có', 'không', 'là', 'gì', 'của', 'về', 'việc', 'các', 'một', 'nội', 'dung', 'nào', 'sao', 'ai', 'cho', 'trong', 'những', 'tất', 'cả', 'bài', 'tập', 'còn', 'thì', 'đâu', 'như', 'thế', 'này'];
                        
                        $clean_question = str_replace(['?', '.', ',', '!', ':', '"', '\''], '', mb_strtolower($search_query));
                        $words = explode(' ', $clean_question);
                        
                        $keywords = [];
                        foreach ($words as $word) {
                            $word = trim($word);
                            // FIX LỖI 1: Chấp nhận từ >= 3 ký tự, HOẶC là số, HOẶC là 1 chữ cái đơn (a, b, c... g)
                            if (!in_array($word, $stop_words) && (mb_strlen($word) >= 3 || is_numeric($word) || preg_match('/^[a-z]$/i', $word))) {
                                $keywords[] = $word;
                            }
                        }
                        $keywords = array_unique($keywords); // Loại bỏ từ trùng

                        // FIX LỖI 3: Tăng kích thước Chunk lên 45 dòng để chứa trọn vẹn danh sách bài tập
                        $lines = explode("\n", $pdf_text); 
                        $chunks = [];
                        $chunk_size = 45; 
                        $overlap = 10;     

                        for ($i = 0; $i < count($lines); $i += ($chunk_size - $overlap)) {
                            $chunk_lines = array_slice($lines, $i, $chunk_size);
                            $chunks[] = implode("\n", $chunk_lines);
                        }
                        
                        $scored_chunks = [];
                        
                        // Chấm điểm từng khối
                        foreach ($chunks as $chunk) {
                            $score = 0;
                            foreach ($keywords as $kw) {
                                if (mb_strlen($kw) <= 2) {
                                    // BẮT BUỘC: Với chữ ngắn như 'g' hay số '5', phải dùng ranh giới từ (\b)
                                    // Để chữ 'g' không match vào chữ 'gửi', chữ '5' không match vào '500'
                                    if (preg_match('/\b' . preg_quote($kw, '/') . '\b/ui', $chunk)) {
                                        $score += 2; // Khớp chữ cái đơn/số độc lập được điểm rất cao
                                    }
                                } else {
                                    // Chữ dài thì tìm chuỗi con bình thường
                                    if (mb_stripos($chunk, $kw) !== false) {
                                        $score++;
                                    }
                                }
                            }
                            if ($score > 0) {
                                $scored_chunks[] = ['text' => $chunk, 'score' => $score];
                            }
                        }

                        // Xếp hạng
                        usort($scored_chunks, function($a, $b) {
                            return $b['score'] <=> $a['score'];
                        });

                        // Lấy kết quả Top
                        $char_count = 0;
                        foreach ($scored_chunks as $item) {
                            $relevant_text .= $item['text'] . "\n...\n"; 
                            $char_count += mb_strlen($item['text']);
                            if ($char_count > 4000) break; // Tăng lên 4000 ký tự
                        }
                    }

                    $final_context = !empty($relevant_text) ? mb_substr($relevant_text, 0, 4000) : mb_substr($pdf_text, 0, 1500);

                    $context_prompt .= "\n\nDưới đây là nội dung trích xuất từ các tài liệu PDF của môn học. BẮT BUỘC phải sử dụng kiến thức này để trả lời nếu sinh viên hỏi:\n";
                    $context_prompt .= "<tai_lieu_mon_hoc>\n" . $final_context . "\n</tai_lieu_mon_hoc>\n";
                    
                    $context_prompt .= "\n[LƯU Ý QUAN TRỌNG CHO AI]: Ngữ cảnh được cung cấp ở trên chỉ là VĂN BẢN (Text) được trích xuất từ file. Bạn hoàn toàn KHÔNG THỂ nhìn thấy các sơ đồ, biểu đồ, hình ảnh minh họa hay ảnh chụp màn hình nằm trong tài liệu gốc. Nếu sinh viên hỏi về chi tiết của một hình ảnh/sơ đồ mà phần chữ không miêu tả, hãy THÀNH THẬT trả lời rằng bạn không nhìn thấy hình ảnh đó và yêu cầu sinh viên mô tả lại nội dung bức ảnh cho bạn.\n";
                    $context_prompt .= "\n[YÊU CẦU TRÍCH DẪN]: Khi bạn sử dụng thông tin từ <tai_lieu_mon_hoc> để trả lời, BẮT BUỘC phải ghi chú tên file ở cuối câu trả lời. Ví dụ: '... (Nguồn tham khảo: Chu de 1-Aug24.pdf)'. Nếu câu hỏi không liên quan đến tài liệu, không cần trích dẫn.\n";    
                }
            }
            $context_prompt .= "Người hỏi: Sinh viên '{$user->lastname} {$user->firstname}'.\n";
            $context_prompt .= "Trả lời ngắn gọn, thân thiện, xưng hô 'mình'/'tôi'. Chỉ trả lời vấn đề liên quan môn học.\n";
        } else {
            $context_prompt = "Bạn là Trợ lý học tập thông minh trên Moodle. Người dùng: '{$user->lastname} {$user->firstname}'.\n";
        }

        return $context_prompt;
    }

    public function call_llm($question, $system_prompt, $userId, $courseId) {
        $history_records = $this->repo->get_chat_history($userId, $courseId, 3);
        $history_prompt = "";

        if (!empty($history_records)) {
            $history_prompt = "\n--- LỊCH SỬ HỘI THOẠI GẦN ĐÂY ---\n";
            foreach ($history_records as $log) {
                $role_name = ($log->role === 'user') ? 'Sinh viên' : 'AI';
                $history_prompt .= "{$role_name}: {$log->message}\n";
            }    
            $history_prompt .= "---------------------------------\n";
        }
        
        $final_prompt = $system_prompt . "\n" . 
                        $history_prompt . "\n" . 
                        "Câu hỏi mới của sinh viên: " . $question;

        return $this->client->generate_content($final_prompt, $this->temperature, $this->max_tokens);
    }
}