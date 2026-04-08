<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_block_ai_tutor_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Phiên bản này sẽ thêm FULLTEXT index để tăng tốc tìm kiếm RAG.
    if ($oldversion < 2024020722) {

        $table = new xmldb_table('block_ai_tutor_chunks');

        // Chỉ thêm index nếu bảng đã tồn tại và index chưa có
        if ($dbman->table_exists($table)) {
            $index = new xmldb_index('content_ft', XMLDB_INDEX_FULLTEXT, ['content']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        // Lưu vết nâng cấp thành công
        upgrade_block_savepoint(true, 2024020722, 'ai_tutor');
    }

    if ($oldversion < 2024020723) {
        $table = new xmldb_table('block_ai_tutor_chunks');
        if ($dbman->table_exists($table)) {
            // Định nghĩa index cũ, có thể bị thừa
            $index = new xmldb_index('courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            // Kiểm tra xem nó có tồn tại không và xóa nó đi
            if ($dbman->index_exists($table, $index)) {
                $dbman->drop_index($table, $index);
            }
        }
        upgrade_block_savepoint(true, 2024020723, 'ai_tutor');
    }

    return true;
}