<?php
// 1. Nhúng cấu hình Moodle - Quan trọng nhất để hết lỗi đỏ
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/adminlib.php');

// 2. Lấy tham số và kiểm tra bảo mật
$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($courseid);
require_capability('moodle/course:update', $context);

// Thiết lập trang
$PAGE->set_url(new moodle_url('/blocks/ai_tutor/manage_links.php', array('courseid' => $courseid)));
$PAGE->set_title("Thiết lập môn tiên quyết");
$PAGE->set_heading("Liên kết kiến thức: " . $course->fullname);
$PAGE->set_context($context);

// 3. Xử lý lưu dữ liệu
if (data_submitted() && confirm_sesskey()) {
    $prereq_id = optional_param('prereq_id', 0, PARAM_INT);
    
    if ($prereq_id > 0 && $prereq_id != $courseid) {
        $record = new \stdClass();
        $record->course_id = $courseid;
        $record->prerequisite_id = $prereq_id;

        if (!$DB->record_exists('block_ai_tutor_course_deps', (array)$record)) {
            $DB->insert_record('block_ai_tutor_course_deps', $record);
            \core\notification::success("Đã thêm liên kết môn học tiên quyết!");
        }
    }
}

// 4. Xử lý xóa dữ liệu
$deleteid = optional_param('delete', 0, PARAM_INT);
if ($deleteid && confirm_sesskey()) {
    $DB->delete_records('block_ai_tutor_course_deps', array('id' => $deleteid, 'course_id' => $courseid));
    \core\notification::info("Đã xóa liên kết.");
}

echo $OUTPUT->header();

// 5. Form thêm môn học
echo $OUTPUT->box_start('generalbox');
echo '<form method="post" action="' . $PAGE->url . '">';
echo '<input type="hidden" name="sesskey" value="'.sesskey().'">';
echo '<h4>Thêm môn học tiên quyết</h4>';

$sql = "SELECT id, fullname FROM {course} WHERE id <> ? AND id <> 1 ORDER BY fullname ASC";
$options = $DB->get_records_sql_menu($sql, [$courseid]);

echo html_writer::select($options, 'prereq_id', '', array('' => '--- Chọn môn học ---'), array('class' => 'custom-select form-control mb-3'));
echo '<button type="submit" class="btn btn-primary">Xác nhận liên kết</button>';
echo '</form>';
echo $OUTPUT->box_end();

// 6. Danh sách đã liên kết
$existing = $DB->get_records_sql("
    SELECT d.id, c.fullname 
    FROM {block_ai_tutor_course_deps} d
    JOIN {course} c ON d.prerequisite_id = c.id
    WHERE d.course_id = ?", [$courseid]);

if ($existing) {
    echo '<h4 class="mt-4">Các môn tiên quyết hiện tại:</h4>';
    echo '<table class="table table-striped mt-2">';
    echo '<thead><tr><th>Tên môn học</th><th>Thao tác</th></tr></thead><tbody>';
    foreach ($existing as $reg) {
        $deleteurl = new moodle_url($PAGE->url, array('delete' => $reg->id, 'sesskey' => sesskey()));
        echo '<tr>';
        echo '<td>' . htmlspecialchars($reg->fullname) . '</td>';
        echo '<td><a href="'.$deleteurl.'" class="btn btn-sm btn-danger">Xóa</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo $OUTPUT->footer();