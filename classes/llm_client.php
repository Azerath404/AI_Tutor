<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();

/**
 * Infrastructure Layer: Client giao tiếp với Ollama (Local LLM)
 */
class llm_client {

    /** @var string */
    private $server_url;
    /** @var string */
    private $model;

    public function __construct($server_url, $model) {
        $this->server_url = rtrim($server_url, '/');
        $this->model = $model;
    }

    /**
     * [Opt F] Trả về tên model đang được dùng (tránh gọi get_config() lần 2 ở service.php).
     */
    public function get_model(): string {
        return $this->model;
    }

    /**
     * [Opt C] Kiểm tra Ollama còn sống không qua /api/tags endpoint (< 3s).
     * Dùng ở ajax.php để báo lỗi sớm trước khi mở streaming.
     *
     * @return bool true nếu Ollama đang chạy và phản hồi HTTP 200.
     */
    public function is_alive(): bool {
        $ch = curl_init($this->server_url . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,  // Tăng lên để hỗ trợ kết nối qua Cloud Tunnel / Ngrok
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['ngrok-skip-browser-warning: true'],
            CURLOPT_SSL_VERIFYPEER => false, // Bỏ qua xác thực SSL trên Windows XAMPP
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    /**
     * Gửi request tới Ollama API và stream kết quả thông qua một callback.
     *
     * @param array    $data     Dữ liệu gửi tới API (model, prompt, stream, options).
     * @param callable $callback Hàm sẽ được gọi cho mỗi chunk dữ liệu nhận được.
     */
    public function stream_generation($data, $callback) {
        $ch = curl_init($this->server_url . '/api/generate');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'ngrok-skip-browser-warning: true'
        ]);
        // 10s: đủ thời gian cho Ollama đang bận load model thiết lập connection.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Bỏ qua xác thực SSL trên Windows XAMPP
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $dataChunk) use ($callback) {
            static $innerBuffer = "";
            $innerBuffer .= $dataChunk;

            // Tách ra các dòng JSON hoàn chỉnh từ buffer
            $lines = explode("\n", $innerBuffer);
            // Phần cuối chưa có "\n" → giữ lại cho lần gọi tiếp
            $innerBuffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                $obj = json_decode($line);
                if ($obj) {
                    if (isset($obj->error)) {
                        throw new \Exception("Ollama API Error: " . $obj->error);
                    }
                    $response = isset($obj->response) ? $obj->response : '';
                    $context = isset($obj->context) ? $obj->context : null;
                    if (isset($obj->response) || isset($obj->context)) {
                        $callback($response, $context);
                    }
                }
            }
            return strlen($dataChunk);
        });
        
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception("cURL Error: " . $err);
        }
        curl_close($ch);
    }
}
