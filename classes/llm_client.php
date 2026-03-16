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
     * Gửi request tới Ollama API
     */
    public function generate_content($prompt, $temperature = 0.7, $max_tokens = 1000) {
        $url = $this->server_url . "/api/generate";
        
        $data = [
            "model" => $this->model,
            "prompt" => $prompt,
            "stream" => false,
            "options" => [
                "temperature" => $temperature,
                "num_predicts" => $max_tokens
            ]
        ];

        $json_data = json_encode($data);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Lengh: ' . strlen($json_data)
            ]);
        
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Lỗi kết nối Ollama Server: ' . $error);
        }
        
        curl_close($ch);
        return $response;
    }
}
