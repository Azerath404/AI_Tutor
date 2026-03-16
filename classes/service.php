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
    
    /** @var repository */
    private $repo;
    /** @var llm_client */
    private $client;

    public function __construct() {
        // Lấy cấu hình từ Admin settings (Dependency Injection simulator)
        $this->api_key = get_config('block_ai_tutor', 'gemini_apikey');
        $this->model = get_config('block_ai_tutor', 'gemini_model') ?: 'gemini-1.5-flash';
        $this->temperature = (float)get_config('block_ai_tutor', 'gemini_temperature') ?: 0.7;
        $this->max_tokens = (int)get_config('block_ai_tutor', 'gemini_maxtokens') ?: 1000;

        if (empty($this->api_key)) {
            throw new \moodle_exception('apikeymissing', 'block_ai_tutor');
        }
        
        // Khởi tạo các thành phần phụ thuộc (Dependencies)
        $this->repo = new repository();
        $this->client = new llm_client($this->api_key, $this->model);
    }

    /**
     * Xây dựng ngữ cảnh (Context) cho Prompt dựa trên khóa học
     */
    public function build_context_prompt($course_id, $user) {
        $context_prompt = "";

        if ($course_id > 1) {
            // Gọi Repository lấy dữ liệu
            $course = $this->repo->get_course($course_id);
            
            if ($course) {
                $course_summary = strip_tags($course->summary);
                $context_prompt = "Bạn là Trợ lý AI (AI Tutor) riêng của môn học: '{$course->fullname}'.\n";
                $context_prompt .= "Mô tả môn học: {$course_summary}\n";

                // RAG: Lấy danh sách hoạt động từ Repository
                $activity_list = $this->repo->get_course_activities_summary($course);
                
                if (!empty($activity_list)) {
                    $context_prompt .= "Danh sách tài liệu/bài tập:\n" . implode("\n", $activity_list) . "\n";
                }
            }
            $context_prompt .= "Người hỏi: Sinh viên '{$user->lastname} {$user->firstname}'.\n";
            $context_prompt .= "Trả lời ngắn gọn, thân thiện, xưng hô 'mình'/'tôi'. Chỉ trả lời vấn đề liên quan môn học.\n";
        } else {
            $context_prompt = "Bạn là Trợ lý học tập thông minh trên Moodle. Người dùng: '{$user->lastname} {$user->firstname}'.\n";
        }

        return $context_prompt;
    }

    /**
     * Gọi API Gemini (Integrator Layer)
     */
    public function call_gemini($question, $system_prompt) {
        $final_prompt = $system_prompt . "\n\nCâu hỏi của sinh viên: " . $question;

        // Gọi Infrastructure Layer
        return $this->client->generate_content($final_prompt, $this->temperature, $this->max_tokens);
    }
}