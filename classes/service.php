<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../vendor/autoload.php');

/**
 * Service Layer: Điều phối RAG và LLM (Đã tối ưu hóa Database và Streaming)
 */
class service {

    /** @var float */
    private $temperature;
    /** @var int */
    private $max_tokens;
    /** @var int */
    private $num_thread;
    /** @var int */
    private $num_ctx;
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
        $this->max_tokens  = (int)get_config('block_ai_tutor', 'ollama_maxtokens') ?: 2500;
        $this->num_thread  = (int)get_config('block_ai_tutor', 'ollama_num_thread') ?: 6;
        $this->num_ctx     = (int)get_config('block_ai_tutor', 'ollama_num_ctx') ?: 2048;

        $this->repo       = new repository();
        $this->doc_parser = new document_parser();
        $this->rag_engine = new rag_engine();

        $ollama_url = get_config('block_ai_tutor', 'ollama_url') ?: 'http://localhost:11434';
        $model      = get_config('block_ai_tutor', 'ollama_model') ?: 'llama3.2';
        $this->llm_client = new llm_client($ollama_url, $model);
    }

    public function get_repo(): repository {
        return $this->repo;
    }

    public function get_doc_parser(): document_parser {
        return $this->doc_parser;
    }

    /**
     * [Opt C] Expose llm_client để ajax.php gọi is_alive() trước khi stream.
     */
    public function get_llm_client(): llm_client {
        return $this->llm_client;
    }

    /**
     * Lắp ráp System Prompt từ ngữ cảnh RAG và thông tin khóa học.
     */
    public function build_context_prompt($course_id, $user, $question = "") {
        global $DB;

        $course = $this->repo->get_course($course_id);
        if (!$course) {
            return "Bạn là trợ lý ảo Moodle.";
        }

        // 1. VAI TRÒ
        $prompt  = "BẠN LÀ AI TUTOR TẠI NHA TRANG UNIVERSITY CHO MÔN: '{$course->fullname}'.\n";
        $prompt .= "NHIỆM VỤ: Trả lời dựa trên tài liệu bài giảng được cung cấp.\n";
        $prompt .= "TÍNH CÁCH: Thân thiện, xưng 'mình' - 'bạn'.\n\n";

        // 2. DANH SÁCH FILE
        $files        = $this->doc_parser->get_processed_files_list($course_id);
        $file_list_str = !empty($files) ? "- " . implode("\n- ", $files) : "Chưa có tài liệu PDF.";

        // 3. NGỮ CẢNH RAG
        $final_context = $this->rag_engine->get_context($course_id, $question);

        $prompt .= "[DANH SÁCH TÀI LIỆU CÓ SẴN]:\n" . $file_list_str . "\n\n";
        $prompt .= "[KIẾN THỨC TRÍCH XUẤT]:\n<context>\n" . $final_context . "\n</context>\n\n";

        // 4. QUY TẮC
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
        // Lấy model từ llm_client (đã khởi tạo trong constructor), không gọi get_config() lần 2.
        $model = $this->llm_client->get_model();

        $data = [
            'model'      => $model,
            'prompt'     => $systemPrompt . "\n\nCâu hỏi: " . $question,
            'stream'     => true,
            'keep_alive' => '120m',
            'options'    => [
                // ── CPU Thread Tuning ────────────────────────────────────────
                // Thiết lập từ cấu hình hoặc mặc định 6
                'num_thread'     => $this->num_thread,

                // ── Context Window ───────────────────────────────────────────
                // Thiết lập từ cấu hình hoặc mặc định 2048
                'num_ctx'        => $this->num_ctx,

                // ── Output Length ────────────────────────────────────────────
                // Giảm từ 400 → 250 tokens:
                // - Câu trả lời học thuật thường đủ ý trong 200-250 tokens.
                // - 150 tokens tiết kiệm ≈ 50 giây generation time.
                // - Sinh viên có thể hỏi tiếp nếu cần chi tiết hơn.
                'num_predict'    => min((int)$this->max_tokens, 250),

                'temperature'    => $this->temperature,
                'repeat_penalty' => 1.1,
                'stop'           => ["\n\n\n", "</s>", "[INST]"],
            ],
        ];


        // Callback chỉ truyền chunk lên — KHÔNG flush ở đây.
        // Flush duy nhất nằm ở ajax.php để tránh double-flush 2x mỗi token.
        $this->llm_client->stream_generation($data, function($chunk) use ($callback) {
            $callback($chunk);
        });
    }
}