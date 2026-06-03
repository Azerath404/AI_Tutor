<?php
/**
 * AI Tutor — Warm-up endpoint v4
 *
 * Thay đổi chính so với v3:
 *  - Bỏ fire-and-forget 2s: PHP cắt kết nối quá sớm → Ollama abort, model không vào RAM.
 *  - Thay bằng blocking call timeout=15s: đủ thời gian load model (benchmark: 5.5s).
 *  - Sau khi curl xong (thành công hoặc timeout), check /api/ps ngay để xác nhận.
 *  - Kết quả: lần đầu gọi mất ~5.5s, trả về 'already_loaded' ngay lập tức.
 */
define('AJAX_SCRIPT', true);
require_once('../../../../config.php');
require_login();
session_write_close();

header('Content-Type: application/json');

try {
    $ollama_url = get_config('block_ai_tutor', 'ollama_url') ?: 'http://localhost:11434';
    $model      = get_config('block_ai_tutor', 'ollama_model') ?: 'llama3.2';
    $num_thread = (int)get_config('block_ai_tutor', 'ollama_num_thread') ?: 6;

    // ── Hàm check /api/ps ─────────────────────────────────────────────────────
    $check_loaded = function() use ($ollama_url, $model) {
        $ch = curl_init($ollama_url . '/api/ps');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, // Tăng lên cho kết nối qua Cloud Tunnel / Ngrok
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['ngrok-skip-browser-warning: true'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$raw) {
            return false;
        }
        $data = json_decode($raw, true);
        if (empty($data['models'])) {
            return false;
        }
        foreach ($data['models'] as $m) {
            // Khớp cả 'llama3.2' lẫn 'llama3.2:latest'
            if (strpos($m['name'], $model) === 0) {
                return $m['name']; // Trả về tên đầy đủ
            }
        }
        return false;
    };

    // ── Bước 1: Kiểm tra model đã trong RAM chưa ─────────────────────────────
    $loaded_name = $check_loaded();
    if ($loaded_name) {
        echo json_encode(['status' => 'already_loaded', 'model' => $loaded_name]);
        exit;
    }

    // ── Bước 2: Load model — chờ đủ thời gian (blocking, tối đa 15s) ─────────
    //
    // Tại sao 15s thay vì 2s (fire-and-forget)?
    //  - CURLOPT_TIMEOUT=2: PHP gửi request, đóng kết nối sau 2s (TCP RST hoặc FIN)
    //    → Ollama nhận FIN/RST trong khi đang load → abort request → model không vào RAM.
    //  - CURLOPT_TIMEOUT=15: PHP chờ response tối đa 15s.
    //    → Nếu Ollama load xong trong 5.5s → curl trả về thành công, model đã trong RAM.
    //    → Nếu vượt 15s → curl timeout, nhưng model CÓ THỂ đã vào RAM (check lại bên dưới).
    //
    // Chi phí: AJAX call này mất 5-15s. Chấp nhận được cho warmup background.
    $payload = json_encode([
        'model'      => $model,
        'prompt'     => 'Xin chao',
        'stream'     => false,
        'keep_alive' => '120m',
        'options'    => [
            'num_predict' => 1,
            'num_ctx'     => 2048,
            'num_thread'  => $num_thread,
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
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 40,   // Tăng lên 40s để hỗ trợ nạp mô hình trên Google Colab / Cloudflare Tunnel (thường mất 15-25s)
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $result  = curl_exec($ch);
    $errCode = curl_errno($ch);
    curl_close($ch);

    // ── Bước 3: Kiểm tra lại sau khi curl xong ───────────────────────────────
    $loaded_name = $check_loaded();
    if ($loaded_name) {
        // ✅ Model đã vào RAM sau khi chờ
        echo json_encode(['status' => 'already_loaded', 'model' => $loaded_name]);
    } else {
        // Có thể Ollama vẫn đang load (model > 15s) → client sẽ poll lại
        echo json_encode(['status' => 'warming_up', 'model' => $model]);
    }

} catch (\Throwable $e) {
    error_log('AI Tutor warmup error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
