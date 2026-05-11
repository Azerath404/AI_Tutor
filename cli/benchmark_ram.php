<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/blocks/ai_tutor/classes/rag_engine.php');

/**
 * Benchmark script để so sánh mức độ sử dụng RAM giữa 2 phương pháp RAG:
 * 1. Phương pháp cũ (Local Cache + Duyệt tuyến tính)
 * 2. Phương pháp mới (MySQL Fulltext Index + Metadata Boosting)
 */

echo "==================================================\n";
echo "Benchmark RAM Usage: AI Tutor RAG Engine\n";
echo "==================================================\n";

// Lấy tham số từ dòng lệnh (nếu có)
$courseid = isset($argv[1]) ? (int)$argv[1] : 2; // Default course ID 2
$query = isset($argv[2]) ? $argv[2] : "hàm là gì trong lập trình"; // Default query

echo "Course ID: $courseid\n";
echo "Query: '$query'\n";
echo "--------------------------------------------------\n";

/**
 * Class mô phỏng phương pháp cũ (sử dụng code gốc từ local_cache/rag_engine.php)
 */
class rag_engine_old {
    public function get_context($courseid, $query) {
        $cache_path = __DIR__ . '/../data/cache/course_' . $courseid . '_chunks.json';

        if (!file_exists($cache_path)) {
            return "";
        }

        // ── BƯỚC 1: Load toàn bộ JSON vào RAM ──────────────────────────
        $raw = file_get_contents($cache_path);
        if (empty($raw)) return "";

        $all_chunks = json_decode($raw, true);
        if (!is_array($all_chunks) || empty($all_chunks)) return "";

        // ── BƯỚC 2: Tách từ khóa từ câu hỏi ────────────────────────────
        $query_words = array_filter(
            explode(' ', mb_strtolower($query)),
            fn($w) => mb_strlen($w) > 1
        );

        if (empty($query_words)) return "";

        // ── BƯỚC 3: Duyệt tuyến tính O(n) ──────────────────────────────
        $scored = [];

        foreach ($all_chunks as $chunk) {
            if (empty($chunk['content'])) continue;

            $content_lower = mb_strtolower($chunk['content']);
            $score         = 0;

            foreach ($query_words as $word) {
                $count  = substr_count($content_lower, $word);
                $score += $count;
            }

            if (!empty($chunk['file'])) {
                $filename_lower = mb_strtolower($chunk['file']);
                foreach ($query_words as $word) {
                    if (mb_strpos($filename_lower, $word) !== false) {
                        $score += 5; 
                    }
                }
            }

            if ($score > 0) {
                $scored[] = [
                    'score' => $score,
                    'chunk' => $chunk,
                ];
            }
        }

        // ── BƯỚC 4: Sort thủ công trong PHP ─────────────────────────────
        usort($scored, fn($a, $b) => $b['score'] - $a['score']);

        // ── BƯỚC 5: Ghép Top-K=5 kết quả thành context ─────────────────
        $top_k   = 5;
        $context = "";

        foreach (array_slice($scored, 0, $top_k) as $item) {
            $context .= $item['chunk']['content'] . "\n---\n";
        }

        return $context;
    }
}

// Hàm chuẩn bị dữ liệu JSON cho test cũ
function prepare_json_cache($courseid) {
    global $DB;
    $dir = __DIR__ . '/../data/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $cache_path = $dir . '/course_' . $courseid . '_chunks.json';
    
    // Luôn ghi đè dữ liệu mới nhất từ DB để test
    $chunks = $DB->get_records('block_ai_tutor_chunks', ['courseid' => $courseid]);
    $json_data = [];
    foreach ($chunks as $chunk) {
        $json_data[] = [
            'id' => $chunk->id,
            'file' => $chunk->filename,
            'content' => $chunk->content
        ];
    }
    file_put_contents($cache_path, json_encode($json_data));
}

function format_bytes($bytes, $precision = 2) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= pow(1024, $pow); 
    return round($bytes, $precision) . ' ' . $units[$pow]; 
} 

// --- Test Phương pháp Mới (Indexed DB) ---
// Dọn dẹp garbage collector để đo chính xác
gc_collect_cycles();
$mem_start_new = memory_get_usage();

$rag_new = new \block_ai_tutor\rag_engine();
$context_new = $rag_new->get_context($courseid, $query);

$mem_end_new = memory_get_usage();
$peak_new = memory_get_peak_usage();
$ram_used_new = $mem_end_new - $mem_start_new;

echo "[PHƯƠNG PHÁP MỚI - MySQL Indexed RAG]\n";
echo "RAM tiêu thụ trong hàm: " . format_bytes($ram_used_new) . "\n";
echo "RAM Peak tổng cộng: " . format_bytes($peak_new) . "\n";
echo "Độ dài context tìm được: " . strlen($context_new) . " ký tự\n";
echo "--------------------------------------------------\n";

// Giải phóng bộ nhớ của test trước
unset($rag_new);
unset($context_new);
gc_collect_cycles();

// --- Test Phương pháp Cũ (Local Cache) ---
// Chuẩn bị file JSON cho phương pháp cũ
prepare_json_cache($courseid);

$mem_start_old = memory_get_usage();

$rag_old = new rag_engine_old();
$context_old = $rag_old->get_context($courseid, $query);

$mem_end_old = memory_get_usage();
$peak_old = memory_get_peak_usage();
$ram_used_old = $mem_end_old - $mem_start_old;

echo "[PHƯƠNG PHÁP CŨ - Local Cache Linear Scan]\n";
echo "RAM tiêu thụ trong hàm: " . format_bytes($ram_used_old) . "\n";
echo "RAM Peak tổng cộng: " . format_bytes($peak_old) . "\n";
echo "Độ dài context tìm được: " . strlen($context_old) . " ký tự\n";
echo "--------------------------------------------------\n";

$diff = $ram_used_old - $ram_used_new;
if ($diff > 0) {
    echo "=> Phương pháp mới TIẾT KIỆM ĐƯỢC: " . format_bytes($diff) . " RAM so với phương pháp cũ.\n";
} else {
    echo "=> Phương pháp mới dùng NHIỀU HƠN: " . format_bytes(abs($diff)) . " RAM so với phương pháp cũ.\n";
}
