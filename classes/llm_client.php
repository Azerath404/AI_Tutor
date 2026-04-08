<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

/**
 * Infrastructure Layer: Client giao tiếp với OLlama (Local LLM)
 */
class llm_client {
    
    private $server_url;
    private $model;

    public function __construct($server_url, $model) {
        $this->server_url = rtrim($server_url, '/');
        $this->model = $model;
    }

    /**
     * Gửi request tới Ollama API và stream kết quả thông qua một callback.
     * @param array $data Dữ liệu gửi tới API (model, prompt, stream, options).
     * @param callable $callback Hàm sẽ được gọi cho mỗi chunk dữ liệu nhận được.
     */
    public function stream_generation($data, $callback) {
    $buffer = '';
    $ch = curl_init($this->server_url . '/api/generate');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $dataChunk) use ($callback) {
        static $innerBuffer = ""; 
        $innerBuffer .= $dataChunk;
        
        // Tìm tất cả các dòng hoàn chỉnh trong buffer
        $lines = explode("\n", $innerBuffer);
        // Giữ lại phần dở dang cuối cùng vào buffer cho lần gọi tới
        $innerBuffer = array_pop($lines); 

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $obj = json_decode($line);
            if ($obj && isset($obj->response)) {
                $callback($obj->response);
            }
        }
        return strlen($dataChunk);
    });
    curl_exec($ch);
    curl_close($ch);
}
}
