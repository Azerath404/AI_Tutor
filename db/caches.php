<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    'rag_context' => [
        'mode' => cache_store::MODE_APPLICATION,
        'ttl'  => 600, // 10 phút
    ],
    // Dùng để giới hạn auto_purge_old_logs() chỉ chạy 1 lần/ngày
    'purge_flag' => [
        'mode' => cache_store::MODE_APPLICATION,
        'ttl'  => 86400, // 24 giờ
    ],
];
