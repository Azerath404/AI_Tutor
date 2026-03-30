<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

/**
 * Service Layer: Nhạc trưởng điều phối RAG và LLM (Hỗ trợ đa khóa học liên kết)
 */
class service {

    private $temperature;
    private $max_tokens;
    
    /** @var repository */
    private $repo;
    /** @var llm_client */
    private $client;
    /** @var document_parser */
    private $doc_parser;
    /** @var rag_engine */
    private $rag_engine;

    public function __construct() {
        $server_url = get_config('block_ai_tutor', 'ollama_url') ?: 'http://localhost:11434';
        $model = get_config('block_ai_tutor', 'ollama_model') ?: 'llama3.2';
        $this->temperature = (float)get_config('block_ai_tutor', 'ollama_temperature') ?: 0.7;
        $this->max_tokens = (int)get_config('block_ai_tutor', 'ollama_maxtokens') ?: 2500;
        
        $this->repo = new repository();
        $this->client = new llm_client($server_url, $model);
        $this->doc_parser = new document_parser();
        $this->rag_engine = new rag_engine();
    }

    public function get_repo() {
        return $this->repo;
    }

    /**
     * Lắp ráp System Prompt hoàn chỉnh (Tối ưu đa khóa học)
     */
    public function build_context_prompt($course_id, $user, $question = "") {
        global $DB;
        if ($course_id <= 1) {
            return "Bạn là trợ lý ảo Moodle.";
        }

        $course = $this->repo->get_course($course_id);
        if (!$course) return "";

        // 1. THIẾT LẬP VAI TRÒ
        $prompt = "BẠN LÀ AI TUTOR CHUYÊN BIỆT CHO MÔN: '{$course->fullname}'.\n";
        $prompt .= "NHIỆM VỤ: Chỉ hỗ trợ sinh viên học lập trình dựa trên tài liệu được cung cấp từ khóa học này và các khóa học tiên quyết liên quan.\n";
        $prompt .= "TÍNH CÁCH: Thân thiện, xưng 'mình' - 'bạn', nhưng cực kỳ nghiêm túc.\n\n";

        // --- PHẦN QUAN TRỌNG: THU THẬP DỮ LIỆU ĐA KHÓA HỌC ---
        
        // Lấy danh sách ID các môn tiên quyết từ bảng liên kết
        $prereq_ids = $DB->get_fieldset_select('block_ai_tutor_course_deps', 'prerequisite_id', 'course_id = ?', [$course_id]);
        
        // Gom môn hiện tại và các môn tiên quyết vào 1 danh sách để quét
        $course_list = array_merge([$course_id], $prereq_ids);
        
        $all_chunks = [];
        $all_files = [];

        foreach ($course_list as $cid) {
            $c_obj = ($cid == $course_id) ? $course : $this->repo->get_course($cid);
            if ($c_obj) {
                // Gọi parser: Nếu chưa có cache cho môn liên kết, nó sẽ TỰ ĐỘNG tạo ở đây
                $chunks = $this->doc_parser->get_pdf_content_from_course($c_obj);
                if (!empty($chunks) && is_array($chunks)) {
                    $all_chunks = array_merge($all_chunks, $chunks);
                    $all_files = array_merge($all_files, array_unique(array_column($chunks, 'file')));
                }
            }
        }

        $final_context = "Không tìm thấy nội dung liên quan trực tiếp trong tài liệu của khóa học hiện tại và các khóa học liên quan.";
        $file_list_str = "Chưa có tài liệu PDF.";

        if (!empty($all_chunks)) {
            // Cập nhật danh sách file hiển thị trong prompt
            $all_files = array_unique($all_files);
            $file_list_str = "- " . implode("\n- ", $all_files);

            // Gọi RAG Engine chấm điểm trên toàn bộ dữ liệu gộp
            $recent_history = $this->repo->get_chat_history($user->id, $course_id, 1);
            $retrieved_context = $this->rag_engine->retrieve_relevant_context($question, $recent_history, $all_chunks);
            
            if (!empty($retrieved_context)) {
                $final_context = $retrieved_context;
            }
        }

        // 3. LẮP RÁP PROMPT
        $prompt .= "[DANH SÁCH FILE CÓ THỂ TRUY XUẤT]:\n" . $file_list_str . "\n\n";
        $prompt .= "[KIẾN THỨC TRÍCH XUẤT TỪ CÁC KHÓA HỌC]:\n<context>\n" . $final_context . "\n</context>\n\n";

        // 4. RÀO CHẮN NGHIÊM NGẶT
        $prompt .= "--- QUY TẮC BẮT BUỘC ---\n";
        $prompt .= "1. PHẠM VI: Chỉ dùng kiến thức trong [KIẾN THỨC TRÍCH XUẤT]. ĐẶC BIỆT chú ý các ví dụ code và hàm nằm trong dấu ngoặc ().\n";
        $prompt .= "2. PHẠM VI: Nếu câu hỏi về môn học tiên quyết, hãy ưu tiên dùng dữ liệu từ các file của môn đó.\n";
        $prompt .= "3. TRUNG THỰC: Tuyệt đối không tự bịa ra các hàm hoặc giải thích sai lệch logic bên ngoài tài liệu. Nếu không có thông tin, hãy nói 'Xin lỗi, tài liệu khóa học không đề cập đến vấn đề này'.\n";
        $prompt .= "4. ĐỊNH DẠNG CODE: Các đoạn mã lệnh (code) BẮT BUỘC phải được định dạng bằng Markdown (sử dụng 3 dấu nháy ngược).\n";
        $prompt .= "5. TRÍCH DẪN NGUỒN: Lấy tên file từ thẻ [Nguồn: ...]. DÒNG CUỐI CÙNG của câu trả lời BẮT BUỘC PHẢI LÀ: (Nguồn tham khảo: <tên file>).\n";
        
        $prompt .= "\nThông tin người hỏi: Sinh viên {$user->firstname} {$user->lastname}.\n";
        $prompt .= "Hãy trả lời câu hỏi sau của sinh viên:\n";

        return $prompt;
    }

    /**
     * Gửi yêu cầu lên Ollama Streaming
     */
    public function call_llm($question, $system_prompt, $userId, $course_id) {
        $history_records = $this->repo->get_chat_history($userId, $course_id, 1);
        $history_prompt = "";

        if (!empty($history_records)) {
            $history_prompt = "\n--- LỊCH SỬ HỘI THOẠI GẦN ĐÂY ---\n";
            foreach ($history_records as $log) {
                $role = ($log->role === 'user') ? 'Sinh viên' : 'AI';
                $history_prompt .= "{$role}: {$log->message}\n";
            }    
            $history_prompt .= "---------------------------------\n";
        }
        
        $reminder = "\n[NHẮC LẠI QUY TẮC QUAN TRỌNG]:\n";
        $reminder .= "- Trả lời chính xác dựa trên ngữ cảnh bài giảng gộp từ các khóa học liên quan.\n";
        $reminder .= "- PHẢI kết thúc bằng dòng: (Nguồn tham khảo: Tên_File.pdf).\n";

        $final_prompt = $system_prompt . "\n" . $history_prompt . $reminder . "\nCâu hỏi mới từ sinh viên: " . $question;
        
        return $this->client->generate_content($final_prompt, $this->temperature, $this->max_tokens);
    }
}