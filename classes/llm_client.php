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
        // THỦ PHẠM 2: Tăng thời gian sống của PHP lên 5 phút (300 giây) để chờ AI đọc file
        set_time_limit(300); 

        $url = $this->server_url . "/api/generate";
        
        $safe_prompt = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $prompt);
        $safe_prompt = mb_convert_encoding($safe_prompt, 'UTF-8', 'UTF-8');

        $data = [
            "model" => $this->model,
            "prompt" => $safe_prompt,
            "stream" => true,
            "options" => [
                "temperature" => $temperature,
                "num_predict" => $max_tokens,
                "num_ctx" => 16384 
            ]
        ];

        $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_INVALID_UTF8_IGNORE);
        
        if ($json_data === false) {
            echo "data: " . json_encode(['error' => 'Lỗi mã hóa JSON: ' . json_last_error_msg()]) . "\n\n";
            ob_flush(); flush();
            return "";
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); 
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_data)
        ]);

        $full_response_text = ""; 
        $buffer = ""; 
        
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$full_response_text, &$buffer){
            $buffer .= $data; 
            $lines = explode("\n", $buffer); 
            $buffer = array_pop($lines);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if(!empty($line)){
                    $json = json_decode($line);
                    
                    if (isset($json->error)) {
                        echo "data: " . json_encode(['error' => 'Lỗi từ Ollama: ' . $json->error]) . "\n\n";
                        ob_flush(); flush();
                        continue;
                    }

                    if(isset($json->response)){
                        $chunk = $json->response;
                        $full_response_text .= $chunk;

                        echo "data: " . json_encode(['text' => $chunk]) . "\n\n";
                        ob_flush(); flush();
                    }
                }
            }
            return strlen($data);
        });

        curl_exec($ch);
        
        // --- GIẢI QUYẾT THỦ PHẠM 1: VẮT KIỆT BỘ ĐỆM (FLUSH BUFFER) ---
        // Nếu kết nối bị ngắt đột ngột, ta lôi đoạn text kẹt trong buffer ra kiểm tra
        if (!empty(trim($buffer))) {
            $json = json_decode(trim($buffer));
            if ($json && isset($json->error)) {
                echo "data: " . json_encode(['error' => 'Ollama Crash (Kẹt đệm): ' . $json->error]) . "\n\n";
                ob_flush(); flush();
            } else if (!$json) {
                // Nếu Ollama ném ra lỗi thô (HTTP 500/404) không phải dạng JSON
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                echo "data: " . json_encode(['error' => "Máy chủ AI từ chối (HTTP $http_code): " . strip_tags(trim($buffer))]) . "\n\n";
                ob_flush(); flush();
            }
        }
        
        if (curl_errno($ch)) {
            echo "data: " . json_encode(['error' => 'Lỗi đường truyền mạng: ' . curl_error($ch)]) . "\n\n";
            ob_flush(); flush();
        }

        curl_close($ch);

        echo "data: [DONE]\n\n";
        ob_flush(); flush();

        return $full_response_text;
    }
}
