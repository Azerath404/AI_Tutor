<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
$script = $CFG->dirroot . '/admin/cli/adhoc_task.php';
$cmd = 'start /B "" php "' . $script . '" --execute > NUL 2> NUL';
echo "Running: $cmd\n";
pclose(popen($cmd, 'r'));
echo "Done firing.\n";
