<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../vendor/autoload.php');

class document_parser {

    public function get_pdf_content_from_course($course) {
        $cache_file = __DIR__ . '/cache_course_' . $course->id . '.json';

        // Nếu file tồn tại và KHÔNG rỗng thì mới trả về
        if (file_exists($cache_file)) {
            $data = json_decode(file_get_contents($cache_file), true);
            if (!empty($data)) {
                return $data;
            }
        }

        $all_chunks = [];
        $processed_hashes = []; 
        $fs = get_file_storage();
        $modinfo = get_fast_modinfo($course);

        $parser = new \Smalot\PdfParser\Parser();

        foreach ($modinfo->cms as $cm) {
            if ($cm->uservisible && in_array($cm->modname, ['resource', 'folder'])) {
                $context = \context_module::instance($cm->id);
                $files = $fs->get_area_files($context->id, 'mod_' . $cm->modname, 'content', 0, 'id ASC', false);
                
                foreach ($files as $file) {
                    if ($file->is_directory() || $file->get_mimetype() !== 'application/pdf') continue;
                    
                    // Chống lặp file dựa trên nội dung
                    $hash = $file->get_contenthash();
                    if (isset($processed_hashes[$hash])) continue;
                    $processed_hashes[$hash] = true;

                    try {
                        // Đọc nội dung file
                        $content = $file->get_content();
                        if (empty($content)) continue;

                        $pdf = $parser->parseContent($content);
                        $text = $pdf->getText();
                        
                        // Làm sạch văn bản
                        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
                        $text = preg_replace('/\s+/', ' ', $text);
                        $text = trim($text);

                        if (empty($text)) continue;

                        $filename = $file->get_filename();
                        
                        // Chia khối (Chunking) theo ký tự để đảm bảo không bị NULL
                        $chunk_limit = 2000;
                        $overlap = 300;
                        $len = mb_strlen($text);

                        // Bước nhảy là giới hạn chunk trừ đi phần chồng lấp
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

                        // Giải phóng bộ nhớ ngay lập tức cho file tiếp theo
                        unset($pdf);
                        unset($text);
                        unset($content);

                    } catch (\Throwable $e) {
                        // Ghi lỗi ra log của Moodle nếu cần debug
                        continue;
                    }
                }
            }
        }

        if (!empty($all_chunks)) {
            // Sử dụng JSON_INVALID_UTF8_SUBSTITUTE để thay thế ký tự UTF-8 lỗi thay vì trả về null
            $json_str = json_encode($all_chunks, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
            file_put_contents($cache_file, $json_str);
        }

        return $all_chunks;
    }
}