<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../vendor/autoload.php');

/**
 * Service Layer: Điều phối RAG và LLM (Đã tối ưu hóa Database và Streaming)
 */
class service {

    private $temperature;
    private $max_tokens;
    
    /** @var llm_client */
    private $llm_client;
    /** @var repository */
    private $repo;
    /** @var document_parser */
    private $doc_parser;
    /** @var rag_engine */
    private $rag_engine;

    public function __construct() {
        $this->temperature = (float)get_config('block_ai_tutor', 'ollama_temperature') ?: 0.7;
        $this->max_tokens = (int)get_config('block_ai_tutor', 'ollama_maxtokens') ?: 2500;
        
        $this->repo = new repository();
        $this->doc_parser = new document_parser();

        $ollama_url = get_config('block_ai_tutor', 'ollama_url') ?: 'http://localhost:11434';
        $model = get_config('block_ai_tutor', 'ollama_model') ?: 'llama3.2';
        $this->llm_client = new llm_client($ollama_url, $model);
        $this->rag_engine = new rag_engine();
    }

    public function get_repo() {
        return $this->repo;
    }

    public function get_doc_parser() {
        return $this->doc_parser;
    }

    /**
     * Lắp ráp System Prompt
     */
    public function build_context_prompt($course_id, $user, $question = "") {
        global $DB;
        
        $course = $this->repo->get_course($course_id);
        if (!$course) return "Bạn là trợ lý ảo Moodle.";

        // 1. THIẾT LẬP VAI TRÒ
        $prompt = "BẠN LÀ AI TUTOR TẠI NHA TRANG UNIVERSITY CHO MÔN: '{$course->fullname}'.\n";
        $prompt .= "NHIỆM VỤ: Trả lời dựa trên tài liệu bài giảng được cung cấp.\n";
        $prompt .= "TÍNH CÁCH: Thân thiện, xưng 'mình' - 'bạn'.\n\n";

        // 2. LẤY DANH SÁCH FILE (Để hiển thị cho AI biết nó có gì)
        // Sử dụng hàm tối ưu chỉ lấy tên file từ DB
        $files = $this->doc_parser->get_processed_files_list($course_id);
        $file_list_str = !empty($files) ? "- " . implode("\n- ", $files) : "Chưa có tài liệu PDF.";

        // 3. TRUY VẤN NGỮ CẢNH (RAG) TỪ DATABASE
        $final_context = $this->rag_engine->get_context($course_id, $question);

        $prompt .= "[DANH SÁCH TÀI LIỆU CÓ SẴN]:\n" . $file_list_str . "\n\n";
        $prompt .= "[KIẾN THỨC TRÍCH XUẤT]:\n<context>\n" . $final_context . "\n</context>\n\n";

        // 4. QUY TẮC PHẢN HỒI
        $prompt .= "--- QUY TẮC ---\n";
        $prompt .= "1. Chỉ dùng kiến thức trong [KIẾN THỨC TRÍCH XUẤT].\n";
        $prompt .= "2. Nếu không có thông tin, hãy nói 'Xin lỗi, mình không tìm thấy nội dung này trong bài giảng'.\n";
        $prompt .= "3. TRÍCH DẪN NGUỒN: Dòng cuối cùng PHẢI là: (Nguồn tham khảo: <tên file>).\n";
        $prompt .= "\nSinh viên đặt câu hỏi: {$user->firstname}.\n";

        return $prompt;
    }

    /**
     * GỌI OLLAMA STREAMING
     */
    public function call_llm_stream($question, $systemPrompt, $callback) {
        $model = get_config('block_ai_tutor', 'ollama_model') ?: 'llama3.2';

        $data = [
            'model' => $model,
            'prompt' => $systemPrompt . "\n\nCâu hỏi: " . $question,
            'stream' => true,
            'options' => [
                'num_thread' => 8,
                'num_predict' => 3000,   // Giới hạn độ dài trả về của AI
                'temperature' => 0.1,  // Độ sáng tạo
                'num_ctx' => 32768
            ]
        ];

        // Ủy quyền việc gọi API cho llm_client
        $this->llm_client->stream_generation($data, function($chunk) use ($callback) {
            $callback($chunk);
            if (ob_get_level() > 0) ob_flush();
            flush();
        });
    }
}