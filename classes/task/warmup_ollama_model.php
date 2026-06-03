<?php
namespace block_ai_tutor\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc task: gọi Ollama API để load model vào RAM (chạy nền qua cron).
 * Không giữ session lock, không block Apache thread.
 */
class warmup_ollama_model extends \core\task\adhoc_task {

    public function get_name() {
        return 'Warm-up Ollama model';
    }

    public function execute() {
        $ollama_url = get_config('block_ai_tutor', 'ollama_url') ?: 'http://localhost:11434';
        $model      = get_config('block_ai_tutor', 'ollama_model') ?: 'llama3.2';

        mtrace("AI Tutor: Warm-up model '$model'...");

        $payload = json_encode([
            'model'      => $model,
            'prompt'     => 'Xin chào',
            'stream'     => false,
            // keep_alive 120 phút: giữ model trong RAM suốt buổi học
            'keep_alive' => '120m',
            'options'    => [
                'num_predict' => 1,
                // PHẢI khớp với num_ctx trong call_llm_stream (4096).
                // Nếu warmup dùng num_ctx khác, Ollama reload KV cache khi nhận request thật.
                'num_ctx'     => 4096,
                // Giúp warmup cũng chạy đúng cấu hình CPU của máy chủ
                'num_thread'  => (int)get_config('block_ai_tutor', 'ollama_num_thread') ?: 6,
            ],
        ]);

        $ch = curl_init($ollama_url . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'ngrok-skip-browser-warning: true'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 130,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            mtrace("AI Tutor: Model '$model' loaded OK.");
        } else {
            mtrace("AI Tutor: Warm-up failed. HTTP=$httpCode, err=$curlErr");
        }
    }
}
