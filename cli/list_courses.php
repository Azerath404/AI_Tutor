<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
global $DB;
$courses = $DB->get_records('course', [], '', 'id, shortname');
foreach($courses as $c) {
    echo $c->id . " - " . $c->shortname . "\n";
}
