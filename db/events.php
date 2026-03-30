<?php
defined('MOODLE_INTERNAL') || die();

// Danh sách các sự kiện mà plugin này sẽ lắng nghe
$observers = [
    [
        'eventname' => '\core\event\course_module_created',
        'callback' => '\block_ai_tutor\observer::schedule_cache_regeneration',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => '\block_ai_tutor\observer::schedule_cache_regeneration',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\block_ai_tutor\observer::schedule_cache_regeneration',
    ],
];
