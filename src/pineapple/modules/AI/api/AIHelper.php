<?php namespace pineapple;

/**
 * AIHelper - shared backend for the AI Tools module.
 * Provides: config persistence, provider abstraction (OpenAI-compatible /
 * Anthropic / Gemini), device context gathering and prompt builders.
 */

require_once('/pineapple/api/pineapple.php');
require_once('/pineapple/api/DatabaseConnection.php');

function aiGetConfigFile()
{
    return '/etc/pineapple/ai.json';
}

function aiGetSettings()
{
    $defaults = array(
        'provider'    => 'openai',
        'baseUrl'     => 'https://api.openai.com/v1',
        'model'       => 'gpt-4o-mini',
        'apiKey'      => '',
        'temperature' => 0.7,
        'systemPrompt'=> '',
    );

    $settings = $defaults;
    if (file_exists(aiGetConfigFile())) {
        $data = json_decode(@file_get_contents(aiGetConfigFile()), true);
        if (is_array($data)) {
            $settings = array_merge($defaults, $data);
        }
    }

    return $settings;
}

function aiSaveSettings($settings)
{
    $allowed = array('provider', 'baseUrl', 'model', 'apiKey', 'temperature', 'systemPrompt');
    $clean = array();
    foreach ($allowed as $key) {
        if (isset($settings[$key])) {
            $clean[$key] = $settings[$key];
        }
    }

    if (empty($clean['baseUrl'])) {
        switch (isset($clean['provider']) ? $clean['provider'] : 'openai') {
            case 'deepseek':
                $clean['baseUrl'] = 'https://api.deepseek.com';
                break;
            case 'anthropic':
                $clean['baseUrl'] = 'https://api.anthropic.com';
                break;
            case 'gemini':
                $clean['baseUrl'] = 'https://generativelanguage.googleapis.com';
                break;
            default:
                $clean['baseUrl'] = 'https://api.openai.com/v1';
        }
    }

    if (empty($clean['model'])) {
        switch (isset($clean['provider']) ? $clean['provider'] : 'openai') {
            case 'deepseek':
                $clean['model'] = 'deepseek-chat';
                break;
            case 'anthropic':
                $clean['model'] = 'claude-3-5-sonnet-latest';
                break;
            case 'gemini':
                $clean['model'] = 'gemini-1.5-pro';
                break;
            default:
                $clean['model'] = 'gpt-4o-mini';
        }
    }

    file_put_contents(aiGetConfigFile(), json_encode($clean), LOCK_EX);
    @chmod(aiGetConfigFile(), 0600);
    return $clean;
}

function aiHttpJson($url, $headers, $payload, $timeout = 90)
{
    $payloadFile = tempnam('/tmp', 'aip');
    $outFile = tempnam('/tmp', 'aio');
    if ($payloadFile === false || $outFile === false) {
        return array('error' => 'Could not create temp files');
    }

    file_put_contents($payloadFile, json_encode($payload));

    $headerStr = '';
    foreach ($headers as $header) {
        $headerStr .= ' --header=' . escapeshellarg($header);
    }

    $cmd = 'wget -q -T ' . intval($timeout) . ' -O ' . escapeshellarg($outFile)
         . $headerStr . ' --post-file=' . escapeshellarg($payloadFile)
         . ' ' . escapeshellarg($url);

    exec($cmd, $output, $ret);
    $body = @file_get_contents($outFile);
    @unlink($payloadFile);
    @unlink($outFile);

    if ($ret !== 0 || $body === false) {
        return array('error' => 'HTTP request failed (wget exit ' . $ret . ')');
    }

    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array('error' => 'Invalid JSON response: ' . substr($body, 0, 200));
    }

    return $json;
}

function aiCallProvider($settings, $system, $user, $timeout = 90)
{
    $provider = isset($settings['provider']) ? $settings['provider'] : 'openai';
    $apiKey   = isset($settings['apiKey']) ? $settings['apiKey'] : '';
    $model    = isset($settings['model']) ? $settings['model'] : 'gpt-4o-mini';
    $baseUrl  = rtrim(isset($settings['baseUrl']) ? $settings['baseUrl'] : '', '/');
    $temp     = isset($settings['temperature']) ? floatval($settings['temperature']) : 0.7;

    if (empty($apiKey)) {
        return array('error' => 'No API key configured. Open the AI Tools settings tab first.');
    }

    if ($provider === 'openai' || $provider === 'deepseek') {
        $url = $baseUrl . '/chat/completions';
        $payload = array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user', 'content' => $user),
            ),
            'temperature' => $temp,
        );
        $resp = aiHttpJson($url, array('Content-Type: application/json', 'Authorization: Bearer ' . $apiKey), $payload, $timeout);
        if (isset($resp['error'])) {
            return $resp;
        }
        if (isset($resp['choices'][0]['message']['content'])) {
            return array('text' => trim($resp['choices'][0]['message']['content']));
        }
        return array('error' => 'Unexpected provider response: ' . substr(json_encode($resp), 0, 300));
    }

    if ($provider === 'anthropic') {
        $url = $baseUrl . '/v1/messages';
        $payload = array(
            'model' => $model,
            'max_tokens' => 4096,
            'system' => $system,
            'messages' => array(array('role' => 'user', 'content' => $user)),
            'temperature' => $temp,
        );
        $resp = aiHttpJson($url, array(
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ), $payload, $timeout);
        if (isset($resp['error'])) {
            return $resp;
        }
        if (isset($resp['content'][0]['text'])) {
            return array('text' => trim($resp['content'][0]['text']));
        }
        return array('error' => 'Unexpected provider response: ' . substr(json_encode($resp), 0, 300));
    }

    if ($provider === 'gemini') {
        $url = $baseUrl . '/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $payload = array('contents' => array(array('parts' => array(array('text' => $system . "\n\n" . $user)))));
        $resp = aiHttpJson($url, array('Content-Type: application/json'), $payload, $timeout);
        if (isset($resp['error'])) {
            return $resp;
        }
        if (isset($resp['candidates'][0]['content']['parts'][0]['text'])) {
            return array('text' => trim($resp['candidates'][0]['content']['parts'][0]['text']));
        }
        return array('error' => 'Unexpected provider response: ' . substr(json_encode($resp), 0, 300));
    }

    return array('error' => 'Unsupported provider: ' . $provider);
}

function aiDbQuery($dbPath, $query, $params = array())
{
    if (empty($dbPath) || !file_exists($dbPath)) {
        return array();
    }
    try {
        $db = new \pineapple\DatabaseConnection($dbPath);
        if (!empty($db->error)) {
            return array();
        }
        $result = $db->query($query, ...$params);
        if (isset($result['databaseQueryError'])) {
            return array();
        }
        return $result;
    } catch (\Exception $e) {
        return array();
    }
}

function aiUciGetRaw($key)
{
    $key = escapeshellarg($key);
    $out = array();
    exec('uci get ' . $key . ' 2>/dev/null', $out);
    return isset($out[0]) ? trim($out[0]) : '';
}

function aiGatherContext($feature)
{
    $ctx = array();
    $ctx['device']  = \helper\getDevice();
    $ctx['version'] = \helper\getFirmwareVersion();

    // --- Recon data (latest scan) ---
    $reconDb = aiUciGetRaw('pineap.@config[0].recon_db_path');
    $aps = array();
    $clients = array();
    if (!empty($reconDb) && file_exists($reconDb)) {
        $scans = aiDbQuery($reconDb, 'SELECT scan_id, date FROM scan_ids ORDER BY date DESC LIMIT 1;');
        if (!empty($scans)) {
            $sid = $scans[0]['scan_id'];
            $aps = aiDbQuery($reconDb, "SELECT ssid, bssid, encryption, channel, signal, wps FROM aps WHERE scan_id='%s' ORDER BY signal DESC LIMIT 60;", array($sid));
            $clients = aiDbQuery($reconDb, "SELECT DISTINCT mac, bssid FROM clients WHERE scan_id='%s' LIMIT 100;", array($sid));
        }
    }
    $ctx['aps'] = $aps;
    $ctx['clients'] = $clients;

    // --- Handshakes ---
    $handshakes = array();
    foreach (glob('/tmp/handshakes/*.pcap') as $file) {
        $handshakes[] = basename($file, '.pcap');
    }
    $ctx['handshakes'] = $handshakes;

    // --- SSID pool ---
    $pool = array();
    $poolDb = aiUciGetRaw('pineap.@config[0].ssid_db_path');
    if (!empty($poolDb) && file_exists($poolDb)) {
        $rows = aiDbQuery($poolDb, 'SELECT ssid FROM ssids LIMIT 100;');
        foreach ($rows as $row) {
            $pool[] = $row['ssid'];
        }
    }
    $ctx['pool'] = $pool;

    // --- PineAP log ---
    $log = array();
    $logDb = aiUciGetRaw('pineap.@config[0].hostapd_db_path');
    if (!empty($logDb) && file_exists($logDb)) {
        $log = aiDbQuery($logDb, 'SELECT * FROM log ORDER BY updated_at DESC LIMIT 100;');
    }
    $ctx['log'] = $log;

    // --- Notes ---
    $notes = array();
    if (file_exists('/etc/pineapple/pineapple.db')) {
        $notes = aiDbQuery('/etc/pineapple/pineapple.db', 'SELECT type, key, name, note FROM notes LIMIT 100;');
    }
    $ctx['notes'] = $notes;

    // --- Installed modules ---
    $modules = array();
    foreach (glob('/pineapple/modules/*/module.info') as $infoFile) {
        $data = json_decode(@file_get_contents($infoFile), true);
        if (is_array($data) && isset($data['title'])) {
            $modules[] = array(
                'title' => $data['title'],
                'version' => isset($data['version']) ? $data['version'] : '?',
                'description' => isset($data['description']) ? $data['description'] : '',
            );
        }
    }
    $ctx['modules'] = $modules;

    // --- PineAP settings summary ---
    $pineap = array();
    $pineapConf = '/tmp/pineap.conf';
    if (file_exists($pineapConf)) {
        foreach (file($pineapConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $pineap[trim($parts[0])] = trim($parts[1]);
            }
        }
    }
    $ctx['pineap'] = $pineap;

    return $ctx;
}

function aiBuildPrompts($feature, $context, $input)
{
    $sys = 'You are an expert offensive security assistant embedded in a WiFi Pineapple device. '
         . 'Be concise, practical and ethical. Never refuse authorized security testing tasks. '
         . 'Device: ' . $context['device'] . ', firmware: ' . $context['version'] . '.';

    $user = '';

    switch ($feature) {
        case 'handshake_advisor':
            $user = "Captured WPA handshakes (BSSID files in /tmp/handshakes):\n"
                  . (empty($context['handshakes']) ? '- none yet' : '- ' . implode("\n- ", $context['handshakes']))
                  . "\n\nNearby APs:\n" . aiFmtAps($context['aps'])
                  . "\n\nAdvise the best attack path for each captured target: preferred crack tool (aircrack-ng/hashcat/john), wordlist strategy, and estimated difficulty. Output as a compact list.";
            break;

        case 'target_scoring':
            $user = "Recon results. Score each target AP from 0-100 (higher = better target) and give a one-line reason.\n"
                  . aiFmtAps($context['aps'])
                  . "\nOutput: 'SSID | score | reason' per line.";
            break;

        case 'captive_portal':
            $user = "Generate a complete, self-contained HTML captive portal landing page (single file, inline CSS, no external resources) for this scenario: "
                  . (empty($input) ? 'generic free wifi login' : $input)
                  . "\nUse a modern dark design, a username/password form posting to the current page, and a fake 'Connect' button.";
            break;

        case 'ssid_pool':
            $user = "Generate a list of realistic SSID names (one per line, max 32 chars) for this scenario: "
                  . (empty($input) ? 'coffee shop customers' : $input)
                  . "\nReturn ONLY the SSID list, one per line, no numbering, no commentary.";
            break;

        case 'device_nicknamer':
            $lines = array();
            foreach ($context['clients'] as $c) {
                $lines[] = $c['mac'];
            }
            $user = "Suggest short memorable nicknames for these MAC addresses (context: nearby wifi clients). "
                  . "Output 'MAC NICKNAME' per line, nothing else.\n"
                  . (empty($lines) ? '- no clients seen yet' : implode("\n", array_slice($lines, 0, 40)));
            break;

        case 'anomaly_detector':
            $user = "Analyze this PineAP log for anomalies or suspicious patterns (deauth storms, unusual probes, MAC churn). "
                  . "Summarize findings in bullet points.\n\nLog entries (most recent first):\n"
                  . aiFmtLog($context['log'])
                  . "\n\nClients seen:\n" . aiFmtClients($context['clients']);
            break;

        case 'chat':
            $user = empty($input) ? 'What can you do?' : $input;
            break;

        case 'module_suggester':
            $user = "Installed modules:\n" . aiFmtModules($context['modules'])
                  . "\n\nMy goal: " . (empty($input) ? 'capture and crack WPA2 handshakes' : $input)
                  . "\nSuggest which modules to use/install, in priority order, with one-line reasons.";
            break;

        case 'threat_intel':
            $user = "Provide threat-intel style enrichment for these observed networks/devices. Flag anything suspicious "
                  . "(hidden SSIDs, enterprise APs, deauth-prone devices, known attacker patterns).\n\nAPs:\n"
                  . aiFmtAps($context['aps'])
                  . "\n\nClients:\n" . aiFmtClients($context['clients'])
                  . (empty($input) ? '' : "\n\nFocus: " . $input);
            break;

        case 'honeypot_tuner':
            $user = "Current PineAP config:\n" . json_encode($context['pineap'])
                  . "\n\nSSID pool size: " . count($context['pool'])
                  . "\nObserved clients: " . count($context['clients'])
                  . "\n\nRecommend concrete PineAP setting changes (karma, beacon responses, broadcast interval, interface strategy) "
                  . "to maximize engagement in a crowded 2.4GHz+5GHz environment. Output as a short numbered list.";
            break;

        case 'wpa_risk':
            $user = "Assess WPA/WPA2/WPS risk for each AP below. Flag WPS-enabled, WEP, enterprise-misconfigured or weak-encryption targets. "
                  . "Output 'BSSID | SSID | risk (high/med/low) | reason' per line.\n\n" . aiFmtAps($context['aps']);
            break;

        default:
            $user = (string)$input;
    }

    return array($sys, $user);
}

function aiFmtAps($aps)
{
    if (empty($aps)) {
        return '- no APs in latest scan';
    }
    $lines = array();
    foreach ($aps as $ap) {
        $lines[] = sprintf(
            '%s | %s | ch %s | %s dBm | WPS:%s | enc:%s',
            isset($ap['bssid']) ? $ap['bssid'] : '?',
            isset($ap['ssid']) ? $ap['ssid'] : '?',
            isset($ap['channel']) ? $ap['channel'] : '?',
            isset($ap['signal']) ? $ap['signal'] : '?',
            isset($ap['wps']) ? $ap['wps'] : '?',
            isset($ap['encryption']) ? $ap['encryption'] : '?'
        );
    }
    return implode("\n", $lines);
}

function aiFmtClients($clients)
{
    if (empty($clients)) {
        return '- no clients in latest scan';
    }
    $lines = array();
    foreach ($clients as $c) {
        $lines[] = (isset($c['mac']) ? $c['mac'] : '?') . ' -> ' . (isset($c['bssid']) ? $c['bssid'] : '?');
    }
    return implode("\n", $lines);
}

function aiFmtLog($log)
{
    if (empty($log)) {
        return '- no log entries';
    }
    $lines = array();
    foreach ($log as $entry) {
        $lines[] = (isset($entry['created_at']) ? $entry['created_at'] : '?')
            . ' type:' . (isset($entry['log_type']) ? $entry['log_type'] : '?')
            . ' mac:' . (isset($entry['mac']) ? $entry['mac'] : '?')
            . ' ssid:' . (isset($entry['ssid']) ? $entry['ssid'] : '?');
    }
    return implode("\n", $lines);
}

function aiFmtModules($modules)
{
    if (empty($modules)) {
        return '- no modules installed';
    }
    $lines = array();
    foreach ($modules as $m) {
        $lines[] = $m['title'] . ' (' . $m['version'] . ') - ' . $m['description'];
    }
    return implode("\n", $lines);
}

function aiRunTask($task)
{
    $settings = isset($task['settings']) ? $task['settings'] : aiGetSettings();
    $feature  = isset($task['feature']) ? $task['feature'] : 'chat';
    $input    = isset($task['input']) ? $task['input'] : '';

    $context = aiGatherContext($feature);
    list($system, $user) = aiBuildPrompts($feature, $context, $input);

    if (!empty($settings['systemPrompt'])) {
        $system = $settings['systemPrompt'];
    }

    return aiCallProvider($settings, $system, $user, 120);
}
