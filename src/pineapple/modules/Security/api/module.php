<?php namespace pineapple;

if (file_exists('/pineapple/modules/Notify/api/Notifier.php')) {
    require_once('/pineapple/modules/Notify/api/Notifier.php');
}

class Security extends SystemModule
{
    private function uciGetRaw($key)
    {
        $key = escapeshellarg($key);
        $out = array();
        exec('uci get ' . $key . ' 2>/dev/null', $out);
        return isset($out[0]) ? trim($out[0]) : '';
    }

    private function getMonitorInterface()
    {
        $iface = $this->uciGetRaw('pineap.@config[0].pineap_interface');
        if (empty($iface)) {
            $iface = 'wlan1mon';
        }
        return $iface;
    }

    private function addPanelNotification($message)
    {
        require_once('/pineapple/api/DatabaseConnection.php');
        try {
            $db = new \pineapple\DatabaseConnection('/etc/pineapple/pineapple.db');
            $db->exec("INSERT INTO notifications (message) VALUES('%s');", $message);
        } catch (\Exception $e) {
            // ignore - notification is best effort
        }
    }

    private function getOwnAPs()
    {
        // Our own AP SSIDs/BSSIDs from the wireless config
        $own = array();
        exec("uci show wireless 2>/dev/null", $lines);
        $ssid = '';
        $bssid = '';
        foreach ($lines as $line) {
            if (strpos($line, 'ssid=') !== false) {
                $ssid = trim(substr($line, strpos($line, '=') + 1), "'");
            } elseif (strpos($line, 'macaddr=') !== false) {
                $bssid = trim(substr($line, strpos($line, '=') + 1), "'");
            } elseif (strpos($line, 'disabled=') !== false && $ssid !== '') {
                $own[] = array('ssid' => $ssid, 'bssid' => $bssid);
                $ssid = '';
                $bssid = '';
            }
        }
        if ($ssid !== '') {
            $own[] = array('ssid' => $ssid, 'bssid' => $bssid);
        }
        return $own;
    }

    private function getLatestScan()
    {
        $reconDb = $this->uciGetRaw('pineap.@config[0].recon_db_path');
        if (empty($reconDb) || !file_exists($reconDb)) {
            return array('aps' => array(), 'clients' => array());
        }

        require_once('/pineapple/api/DatabaseConnection.php');
        $db = new \pineapple\DatabaseConnection($reconDb);
        $scans = $db->query('SELECT scan_id, date FROM scan_ids ORDER BY date DESC LIMIT 1;');
        if (empty($scans)) {
            return array('aps' => array(), 'clients' => array());
        }
        $sid = $scans[0]['scan_id'];

        $aps = $db->query("SELECT ssid, bssid, encryption, channel, signal, wps, last_seen FROM aps WHERE scan_id='%s';", $sid);
        $clients = $db->query("SELECT DISTINCT mac, bssid FROM clients WHERE scan_id='%s';", $sid);
        return array('aps' => $aps, 'clients' => $clients);
    }

    private function getScanData()
    {
        $data = $this->getLatestScan();
        $this->response = array(
            'success' => true,
            'aps' => $data['aps'],
            'clients' => $data['clients'],
            'ownAPs' => $this->getOwnAPs(),
        );
    }

    private function getFingerprintData()
    {
        // #24 vendor fingerprinting: unique MACs + probe/event counts from the PineAP log
        $data = $this->getLatestScan();
        $devices = array();

        $seen = array();
        foreach ($data['aps'] as $ap) {
            $mac = strtoupper($ap['bssid']);
            if (isset($seen[$mac])) {
                continue;
            }
            $seen[$mac] = true;
            $devices[] = array('mac' => $mac, 'kind' => 'AP', 'ssid' => $ap['ssid'], 'channel' => $ap['channel']);
        }
        foreach ($data['clients'] as $client) {
            $mac = strtoupper($client['mac']);
            if (isset($seen[$mac])) {
                continue;
            }
            $seen[$mac] = true;
            $devices[] = array('mac' => $mac, 'kind' => 'Client', 'ssid' => '', 'channel' => '');
        }

        // probe / event counts from hostapd log db
        $logDb = $this->uciGetRaw('pineap.@config[0].hostapd_db_path');
        if (!empty($logDb) && file_exists($logDb)) {
            require_once('/pineapple/api/DatabaseConnection.php');
            $db = new \pineapple\DatabaseConnection($logDb);
            $rows = $db->query('SELECT mac, COUNT(*) as events FROM log GROUP BY mac ORDER BY events DESC LIMIT 200;');
            $counts = array();
            foreach ($rows as $row) {
                $counts[strtoupper($row['mac'])] = $row['events'];
            }
            foreach ($devices as &$device) {
                $device['events'] = isset($counts[$device['mac']]) ? $counts[$device['mac']] : 0;
            }
        }

        $this->response = array('success' => true, 'devices' => $devices);
    }

    private function startDeauthScan()
    {
        $duration = isset($this->request->duration) ? intval($this->request->duration) : 15;
        if ($duration < 3 || $duration > 120) {
            $duration = 15;
        }
        $iface = $this->getMonitorInterface();

        @unlink('/tmp/deauth_scan.txt');
        @unlink('/tmp/deauth_scan.done');

        $cmd = 'timeout ' . $duration . " tcpdump -i {$iface} -nn -l 'type mgmt subtype deauth or type mgmt subtype disassoc' > /tmp/deauth_scan.txt 2>&1; touch /tmp/deauth_scan.done";
        $this->execBackground($cmd);

        $this->response = array('success' => true, 'duration' => $duration, 'interface' => $iface);
    }

    private function getDeauthScanStatus()
    {
        if (!file_exists('/tmp/deauth_scan.done')) {
            $this->response = array('running' => true, 'success' => true);
            return;
        }

        $frames = 0;
        $counts = array();
        if (file_exists('/tmp/deauth_scan.txt')) {
            $lines = file('/tmp/deauth_scan.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (stripos($line, 'deauth') === false && stripos($line, 'disassoc') === false) {
                    continue;
                }
                $frames++;
                if (preg_match_all('/([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}/', $line, $matches)) {
                    foreach ($matches[0] as $mac) {
                        $mac = strtoupper($mac);
                        if (!isset($counts[$mac])) {
                            $counts[$mac] = 0;
                        }
                        $counts[$mac]++;
                    }
                }
            }
        }

        arsort($counts);
        $top = array_slice($counts, 0, 10, true);
        $topSources = array();
        foreach ($top as $mac => $count) {
            $topSources[] = array('mac' => $mac, 'count' => $count);
        }

        $this->response = array('running' => false, 'frames' => $frames, 'sources' => $topSources, 'success' => true);
    }

    private function sendAlert()
    {
        $message = isset($this->request->message) ? $this->request->message : '';
        if (empty($message)) {
            $this->error = 'Alert message is empty';
            return;
        }

        // panel notification
        $this->addPanelNotification($message);

        // telegram/discord via Notifier (if the Notify module is present)
        $notifyResults = array();
        if (class_exists('\pineapple\Notifier')) {
            $result = \pineapple\Notifier::send('[Security] ' . $message);
            if (isset($result['results'])) {
                $notifyResults = $result['results'];
            }
        }

        $this->response = array(
            'success' => true,
            'notifyResults' => $notifyResults,
        );
    }

    public function route()
    {
        switch ($this->request->action) {
            case 'getScanData':
                $this->getScanData();
                break;
            case 'getFingerprintData':
                $this->getFingerprintData();
                break;
            case 'startDeauthScan':
                $this->startDeauthScan();
                break;
            case 'getDeauthScanStatus':
                $this->getDeauthScanStatus();
                break;
            case 'sendAlert':
                $this->sendAlert();
                break;
            default:
                $this->error = "Unknown action: " . $this->request->action;
        }
    }
}
