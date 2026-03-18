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
            "stream" => true,
            "options" => [
                "temperature" => $temperature,
                "num_predict" => $max_tokens
            ]
        ];

        $json_data = json_encode($data);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Tắt chờ đồng bộ
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_data)
            ]);

        $full_response_text = ""; // Lưu toàn bộ câu để cho vào database sau khi xong
        
        // Callback function: Mỗi khi Ollama trả về 1 chữ thì hàm chạy
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$full_response_text){
            $lines = explode("\n", trim($data));
            foreach ($lines as $line) {
                if(!empty($line)){
                    $json = json_decode($line);
                    if(isset($json->response)){
                        $chunk = $json->response;
                        $full_response_text .= $chunk;

                        // Đóng gói theo chuẩn Server-Sent Events và đẩy đi ngay
                        echo "data: " .json_encode(['text' => $chunk]) . "\n\n";
                        ob_flush();
                        flush();
                    }
                }
            }
            return strlen($data);
        });

        curl_exec($ch);
        curl_close($ch);

        // Gửi tín hiệu báo hiệu AI đã gõ xong
        echo "data: [DONE]\n\n";
        ob_flush();
        flush();

        // Trả về toàn bộ chuỗi cho file ajax.php lưu vào db
        return $full_response_text;
    }
}
