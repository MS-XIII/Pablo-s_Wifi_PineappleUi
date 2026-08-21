<?php namespace pineapple;

/**
 * Notifier - shared helper used by the Notify module and other modules
 * (Security, PineAP...) to push messages to Telegram and/or Discord.
 * Config lives in /etc/pineapple/notify.json.
 */
class Notifier
{
    const CONFIG_FILE = '/etc/pineapple/notify.json';

    public static function getConfig()
    {
        $defaults = array(
            'telegramEnabled' => false,
            'telegramToken'   => '',
            'telegramChatId'  => '',
            'discordEnabled'  => false,
            'discordWebhook'  => '',
        );

        $config = $defaults;
        if (file_exists(self::CONFIG_FILE)) {
            $data = json_decode(@file_get_contents(self::CONFIG_FILE), true);
            if (is_array($data)) {
                $config = array_merge($defaults, $data);
            }
        }

        return $config;
    }

    public static function saveConfig($config)
    {
        $allowed = array('telegramEnabled', 'telegramToken', 'telegramChatId', 'discordEnabled', 'discordWebhook');
        $clean = array();
        foreach ($allowed as $key) {
            if (isset($config[$key])) {
                $clean[$key] = $config[$key];
            }
        }

        file_put_contents(self::CONFIG_FILE, json_encode($clean), LOCK_EX);
        @chmod(self::CONFIG_FILE, 0600);
        return $clean;
    }

    private static function httpPostJson($url, $payload, $timeout = 30)
    {
        $payloadFile = tempnam('/tmp', 'not');
        $outFile = tempnam('/tmp', 'not');
        if ($payloadFile === false || $outFile === false) {
            return array('error' => 'Could not create temp files');
        }

        file_put_contents($payloadFile, json_encode($payload));
        $cmd = 'wget -q -T ' . intval($timeout) . ' -O ' . escapeshellarg($outFile)
             . ' --header=Content-Type:application/json --post-file=' . escapeshellarg($payloadFile)
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

    public static function sendTelegram($token, $chatId, $message)
    {
        if (empty($token) || empty($chatId)) {
            return array('error' => 'Telegram token/chat id not configured');
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $resp = self::httpPostJson($url, array(
            'chat_id' => $chatId,
            'text' => $message,
            'disable_web_page_preview' => true,
        ));

        if (isset($resp['error'])) {
            return $resp;
        }
        if (isset($resp['ok']) && $resp['ok'] === true) {
            return array('success' => true);
        }

        return array('error' => 'Telegram error: ' . json_encode($resp));
    }

    public static function sendDiscord($webhook, $message)
    {
        if (empty($webhook)) {
            return array('error' => 'Discord webhook not configured');
        }

        $resp = self::httpPostJson($webhook, array('content' => $message));
        if (isset($resp['error'])) {
            return $resp;
        }

        // Discord returns 204 No Content on success -> empty body
        return array('success' => true);
    }

    /**
     * Send a message to every enabled channel. Returns a summary array.
     */
    public static function send($message)
    {
        $config = self::getConfig();
        $results = array();

        if (!empty($config['telegramEnabled'])) {
            $results['telegram'] = self::sendTelegram($config['telegramToken'], $config['telegramChatId'], $message);
        }

        if (!empty($config['discordEnabled'])) {
            $results['discord'] = self::sendDiscord($config['discordWebhook'], $message);
        }

        if (empty($results)) {
            return array('error' => 'No notification channel enabled (enable Telegram, Discord or both)');
        }

        return array('success' => true, 'results' => $results);
    }
}
