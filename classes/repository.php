<?php
namespace block_ai_tutor;

defined('MOODLE_INTERNAL') || die();
// Nạp thư viện đọc PDF
require_once(__DIR__ . '/../vendor/autoload.php');

/**
 * Repository Layer: Chuyên trách truy xuất dữ liệu từ Moodle Database
 */
class repository {

    /**
     * Lấy thông tin khóa học theo ID
     */
    public function get_course($course_id) {
        global $DB;
        return $DB->get_record('course', ['id' => $course_id]);
    }

    /**
     * Lấy danh sách các hoạt động (Activities) trong khóa học để làm ngữ cảnh
     */
    public function get_course_activities_summary($course) {
        // Sử dụng hàm modinfo của Moodle để lấy cache nhanh
        $modinfo = get_fast_modinfo($course);
        $activity_list = [];
        
        foreach ($modinfo->cms as $cm) {
            // Chỉ lấy những module sinh viên có thể thấy
            if ($cm->uservisible) {
                // Format: - Tên bài (Loại module)
                $activity_list[] = "- " . $cm->name . " (Loại: " . $cm->modname . ")";
            }
        }
        
        return $activity_list;
    }

    /**
     * Lưu tin nhắn vào database
     */
    public function save_chat_log($userId, $courseId, $role, $message){
        global $DB;
        $record = new \stdClass();
        $record->userId = $userId;
        $record->courseId = $courseId;
        $record->role = $role;
        $record->message = $message;
        $record->timecreated = time();

        return $DB->insert_record('block_ai_tutor_logs', $record);
    }

    /**
     * Lấy N tin nhắn gần nhất của Sinh viên trong khóa học này
     */
    public function get_chat_history($userId, $courseId, $limit = 5){
        global $DB;
        // Lấy $limit tin nhắn mới nhất, sắp xếp theo thời gian
        $query = "SELECT * FROM {block_ai_tutor_logs} 
                WHERE userid = ? AND courseid = ? 
                ORDER BY timecreated DESC, id DESC";
        $records = $DB->get_records_sql($query, [$userId, $courseId], 0, $limit);
        if ($records){
            return array_reverse($records);
        }
        return [];
    }

    /**
     * (RAG CORE): Trích xuất nội dung chữ từ các file PDF (CÓ TÍCH HỢP CACHING)
     */
    public function get_pdf_content_from_course($course) {
        // 1. TẠO ĐƯỜNG DẪN FILE CACHE (Dựa theo ID khóa học)
        $cache_file = __DIR__ . '/cache_course_' . $course->id . '.txt';

        // 2. KIỂM TRA CACHE: Nếu file cache đã tồn tại -> Đọc ngay (0.001 giây)
        if (file_exists($cache_file)) {
            return file_get_contents($cache_file);
        }

        // 3. NẾU CHƯA CÓ CACHE: Bắt đầu quá trình quét tốn thời gian
        $full_text = "--- LOG QUÁ TRÌNH QUÉT FILE ---\n";
        $fs = get_file_storage();
        $modinfo = get_fast_modinfo($course);

        foreach ($modinfo->cms as $cm) {
            if ($cm->uservisible && in_array($cm->modname, ['resource', 'folder'])) {
                $full_text .= "\n[TÌM THẤY MODULE]: " . $cm->name . " (Loại: " . $cm->modname . ")\n";
                $context = \context_module::instance($cm->id);
                $component = 'mod_' . $cm->modname;
                
                $files = $fs->get_area_files($context->id, $component, 'content', 0, 'id ASC', false);
                $full_text .= "  => Số lượng file bên trong: " . count($files) . "\n";
                
                foreach ($files as $file) {
                    if ($file->is_directory()) continue; // Bỏ qua file thư mục ảo của Moodle
                    
                    $full_text .= "  -> Đang xét file: " . $file->get_filename() . " | Định dạng: " . $file->get_mimetype() . "\n";
                    
                    if ($file->get_mimetype() === 'application/pdf') {
                        try {
                            $full_text .= "     [TIẾN HÀNH ĐỌC PDF...]\n";
                            $parser = new \Smalot\PdfParser\Parser();
                            $pdf_content = $file->get_content(); 
                            $pdf = $parser->parseContent($pdf_content);
                            $text = $pdf->getText();
                            $text = preg_replace('/\s+/', ' ', $text);
                            
                            $full_text .= "     [ĐỌC THÀNH CÔNG!] Kích thước chữ: " . strlen($text) . " bytes\n";
                            $full_text .= "--- Nội dung file " . $file->get_filename() . " ---\n";
                            $full_text .= $text . "\n";
                            
                        } catch (\Throwable $e) { 
                            $full_text .= "     [LỖI NGHIÊM TRỌNG]: " . $e->getMessage() . "\n";
                        }
                    } else {
                        $full_text .= "     [BỎ QUA] Không phải file PDF.\n";
                    }
                }
            }
        }
        $full_text .= "--- KẾT THÚC QUÉT FILE ---\n";

        // 4. LƯU KẾT QUẢ VÀO CACHE: Để lần chat sau không phải quét lại nữa
        if (!empty(trim($full_text))) {
            file_put_contents($cache_file, $full_text);
        }

        return $full_text;
    }
}