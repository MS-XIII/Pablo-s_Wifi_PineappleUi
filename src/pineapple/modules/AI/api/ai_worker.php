<?php
/**
 * AI worker - background task runner for the AI Tools module.
 * Usage: php ai_worker.php <taskId>
 * Reads  /tmp/ai_task_<taskId>.json
 * Writes /tmp/ai_result_<taskId>.json
 */

require_once('/pineapple/modules/AI/api/AIHelper.php');

$taskId = isset($argv[1]) ? preg_replace('/[^0-9_]/', '', $argv[1]) : '';
if (empty($taskId)) {
    exit(1);
}

$taskFile = '/tmp/ai_task_' . $taskId . '.json';
$resultFile = '/tmp/ai_result_' . $taskId . '.json';

$task = json_decode(@file_get_contents($taskFile), true);
if (!is_array($task)) {
    file_put_contents($resultFile, json_encode(array('error' => 'Task file missing or invalid')));
    exit(1);
}

$result = \pineapple\aiRunTask($task);
file_put_contents($resultFile, json_encode($result));
exit(0);
