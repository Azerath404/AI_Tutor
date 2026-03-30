<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../vendor/autoload.php');

class document_parser {

    public function get_pdf_content_from_course($course) {
        $cache_file = __DIR__ . '/../data/cache_course_' . $course->id . '.json';
        $log_file = __DIR__ . '/../data/log_files.txt';

        if (file_exists($cache_file)) {
            // Nếu file cache đã tồn tại, đọc và trả về nội dung, không cần xử lý lại.
            $data = json_decode(file_get_contents($cache_file), true);
            if (!empty($data)) return $data;
        }

        $all_chunks = [];
        $processed_hashes = []; 
        $fs = get_file_storage();
        $modinfo = get_fast_modinfo($course);
        $parser = new \Smalot\PdfParser\Parser();

        // Khởi tạo log mới (ghi đè 'w' thay vì 'a' để sạch log cũ)
        file_put_contents($log_file, "--- BẮT ĐẦU QUÉT KHÓA HỌC: $course->id ---\n", FILE_APPEND);

        foreach ($modinfo->cms as $cm) {
            // TẦNG LỌC 1: Chỉ xử lý nếu Resource này đang HIỂN THỊ (không bị hide, không bị xóa tạm)
            if (!$cm->uservisible || $cm->deletioninprogress) {
                continue;
            }

            if ($cm->modname !== 'resource' && $cm->modname !== 'folder') {
                continue;
            }

            $context = \context_module::instance($cm->id);
            $component = 'mod_' . $cm->modname;
            $files = $fs->get_area_files($context->id, $component, 'content', 0, 'id ASC', false);
            
            foreach ($files as $file) {
                // TẦNG LỌC 3: Loại bỏ thư mục và file hệ thống
                if ($file->is_directory() || $file->get_filename() === '.') {
                    continue;
                }

                // TẦNG LỌC 4: Kiểm tra dung lượng (file xóa lỗi thường có size = 0 hoặc 1)
                if ($file->get_filesize() <= 0) {
                    continue;
                }

                $content = $file->get_content();
                if (empty($content)) continue;

                $hash = $file->get_contenthash();
                if (isset($processed_hashes[$hash])) continue;
                $processed_hashes[$hash] = true;

                $filename = $file->get_filename();
                file_put_contents($log_file, "-> Đang xử lý CHÍNH THỨC: $filename \n", FILE_APPEND);

                try {
                    $pdf = $parser->parseContent($content);
                    $text = $pdf->getText();
                    
                    // Logic OCR và Clean Text giữ nguyên như cũ...
                    $unicode_replacement_char = "\xEF\xBF\xBD"; 
                    $error_count = substr_count($text, $unicode_replacement_char);
                    $total_len = mb_strlen($text);
                    $is_corrupted = ($total_len > 0 && ($error_count / $total_len) > 0.05);

                    if (empty(trim($text)) || $is_corrupted) {
                        $text = $this->run_ocr_python($file);
                    }

                    // ... (Đoạn làm sạch văn bản và chunk_text giữ nguyên) ...
                    if (!empty(trim($text))) {
                        $this->chunk_text($text, $filename, $all_chunks);
                    }
                } catch (\Throwable $e) {
                    file_put_contents($log_file, "   [X] Lỗi: " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }
        }

        if (!empty($all_chunks)) {
            file_put_contents($cache_file, json_encode($all_chunks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        return $all_chunks;
    }

    private function run_ocr_python($file) {
        try {
            $temp_dir = make_temp_directory('block_ai_tutor');
            $temp_path = $temp_dir . '/' . $file->get_contenthash() . '.pdf';
            $file->copy_content_to($temp_path);

            $python_exe = 'python'; 
            $script_path = __DIR__ . '/../scripts/ocr_processor.py'; 
            
            $command = escapeshellcmd("$python_exe $script_path " . escapeshellarg($temp_path));
            $output = shell_exec($command);

            @unlink($temp_path);
            return $output ?: '';
        } catch (\Exception $e) {
            return '';
        }
    }

    private function chunk_text($text, $filename, &$all_chunks) {
        $chunk_limit = 1200; 
        $overlap = 150;      
        $len = mb_strlen($text);
        $step = $chunk_limit - $overlap;

        for ($i = 0; $i < $len; $i += $step) {
            $sub_text = mb_substr($text, $i, $chunk_limit);
            if (mb_strlen(trim($sub_text)) > 20) {
                $all_chunks[] = [
                    'file' => $filename,
                    'content' => "[Nguồn: $filename]\n" . $sub_text
                ];
            }
        }
    }
}