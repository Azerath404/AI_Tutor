<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Danh sách các sự kiện mà plugin AI Tutor lắng nghe.
 * Tất cả các thay đổi về tài liệu (Thêm/Sửa/Xóa) đều được gom về hàm 'handle_content_change'
 * để đảm bảo xóa cache cũ tức thì và đăng ký tạo cache mới ngầm.
 */
$observers = [
    // 1. Quản lý Block: Tự động thêm block khi tạo khóa học
    [
        'eventname'   => '\core\event\course_created',
        'callback'    => '\block_ai_tutor\observer::auto_add_ai_block',
    ],

    // 2. NHÓM THAY ĐỔI TÀI LIỆU (Tạo mới, Cập nhật, Xóa)
    // Bắt sự kiện ở cấp độ Module (Folder, Resource...)
    [
        'eventname'   => '\core\event\course_module_created',
        'callback'    => '\block_ai_tutor\observer::handle_content_change',
    ],
    [
        'eventname'   => '\core\event\course_module_updated',
        'callback'    => '\block_ai_tutor\observer::handle_content_change',
    ],
    [
        'eventname'   => '\core\event\course_module_deleted',
        'callback'    => '\block_ai_tutor\observer::handle_content_change',
    ],

    // Bắt sự kiện ở cấp độ File (Khi thêm/xóa file lẻ trong Folder)
    [
        'eventname'   => '\core\event\file_created',
        'callback'    => '\block_ai_tutor\observer::handle_content_change',
    ],
    [
        'eventname'   => '\core\event\file_deleted',
        'callback'    => '\block_ai_tutor\observer::handle_content_change',
    ],

    // Bắt sự kiện khi nội dung khóa học bị dọn dẹp hoặc gỡ bỏ
    [
        'eventname'   => '\core\event\course_content_deleted',
        'callback'    => '\block_ai_tutor\observer::handle_content_change',
    ]
];