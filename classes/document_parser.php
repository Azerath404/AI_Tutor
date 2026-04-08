<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../vendor/autoload.php');

class document_parser {

    /**
     * Xử lý tất cả các file PDF trong một khóa học, thực hiện OCR nếu cần,
     * chia thành các chunk và lưu vào database.
     * Hàm này được thiết kế để chạy trong một tác vụ nền (background task).
     * @param \stdClass $course Đối tượng khóa học của Moodle.
     */
    public function process_and_save_chunks_for_course($course) {
        global $DB;

        $all_chunks = [];
        $processed_hashes = [];
        $fs = get_file_storage();
        $modinfo = get_fast_modinfo($course);
        $parser = new \Smalot\PdfParser\Parser();

        foreach ($modinfo->cms as $cm) {
            if (!$cm->uservisible || $cm->deletioninprogress) continue;
            if ($cm->modname !== 'resource' && $cm->modname !== 'folder') continue;

            $context = \context_module::instance($cm->id);
            $component = 'mod_' . $cm->modname;
            $files = $fs->get_area_files($context->id, $component, 'content', 0, 'filename ASC', false);
            
            foreach ($files as $file) {
                if ($file->is_directory() || $file->get_filename() === '.') continue;
                if ($file->get_filesize() <= 0) continue;

                $content = $file->get_content();
                if (empty($content)) continue;

                $hash = $file->get_contenthash();
                if (isset($processed_hashes[$hash])) continue;
                $processed_hashes[$hash] = true;

                $filename = $file->get_filename();

                try {
                    $pdf = $parser->parseContent($content);
                    $text = $pdf->getText();
                    
                    $unicode_replacement_char = "\xEF\xBF\xBD"; 
                    $error_count = substr_count($text, $unicode_replacement_char);
                    $total_len = mb_strlen($text);
                    $is_corrupted = ($total_len > 0 && ($error_count / $total_len) > 0.05);

                    if (empty(trim($text)) || $is_corrupted) {
                        $text = $this->run_ocr_python($file);
                    }

                    if (!empty(trim($text))) {
                        $this->chunk_text($text, $filename, $all_chunks);
                    }
                } catch (\Throwable $e) {
                    error_log("AI Tutor Error: " . $e->getMessage());
                }
            }
        }

        // Sau khi xử lý tất cả các file, lưu các chunk mới vào DB.
        if (!empty($all_chunks)) {
            $this->save_chunks_to_db($course->id, $all_chunks);
        }
    }

    public function get_processed_files_list($courseid) {
        global $DB;
        $sql = "SELECT DISTINCT filename FROM {block_ai_tutor_chunks} WHERE courseid = ?";
        $records = $DB->get_records_sql($sql, [$courseid]);
        return array_keys($records);
    }

    private function save_chunks_to_db($courseid, $chunks) {
        // Xóa tất cả các chunk cũ của khóa học này trước khi chèn chunk mới.
        // Đây là một bước quan trọng để đảm bảo tính nhất quán của dữ liệu.
        global $DB;
        $DB->delete_records('block_ai_tutor_chunks', ['courseid' => $courseid]);
        foreach ($chunks as $chunk) {
            $record = new \stdClass();
            $record->courseid = $courseid;
            $record->filename = $chunk['file'];
            $record->content  = $chunk['content'];
            $record->hash     = md5($chunk['content']);
            $DB->insert_record('block_ai_tutor_chunks', $record);
        }
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

    /**
     * Hàm này được gọi bởi ajax.php để đảm bảo rằng các chunk đã được tạo.
     * Nếu chưa có, nó sẽ kích hoạt việc xử lý một cách đồng bộ.
     * Đây là một cơ chế dự phòng (fallback) trong trường hợp cron chưa chạy.
     * @param int $courseid
     */
    public function ensure_chunks_exist($courseid) {
        global $DB;

        $chunks_exist = $DB->record_exists('block_ai_tutor_chunks', ['courseid' => $courseid]);

        if (!$chunks_exist) {
            $course = get_course($courseid);
            if ($course) {
                $this->process_and_save_chunks_for_course($course);
            }
        }
    }
}