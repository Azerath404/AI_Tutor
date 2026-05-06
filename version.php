<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_ai_tutor';
$plugin->version = 2024020730; // Thêm cache rag_context + warm-up Ollama model
$plugin->requires = 2022041900;
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = 'v2.0';  