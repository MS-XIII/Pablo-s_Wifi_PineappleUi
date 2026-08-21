<?php namespace pineapple;

require_once('Notifier.php');

class Notify extends SystemModule
{
    private function getSettings()
    {
        $this->response = array('settings' => Notifier::getConfig(), 'success' => true);
    }

    private function saveSettings()
    {
        $settings = (array)$this->request->settings;
        $clean = Notifier::saveConfig($settings);
        $this->response = array('settings' => $clean, 'success' => true);
    }

    private function testTelegram()
    {
        $settings = (array)$this->request->settings;
        $result = Notifier::sendTelegram($settings['telegramToken'], $settings['telegramChatId'], 'WiFi Pineapple notification test: Telegram is working!');
        if (isset($result['success'])) {
            $this->response = array('success' => true);
        } else {
            $this->error = isset($result['error']) ? $result['error'] : 'Telegram test failed';
        }
    }

    private function testDiscord()
    {
        $settings = (array)$this->request->settings;
        $result = Notifier::sendDiscord($settings['discordWebhook'], 'WiFi Pineapple notification test: Discord is working!');
        if (isset($result['success'])) {
            $this->response = array('success' => true);
        } else {
            $this->error = isset($result['error']) ? $result['error'] : 'Discord test failed';
        }
    }

    private function sendMessage()
    {
        $message = isset($this->request->message) ? $this->request->message : '';
        if (empty($message)) {
            $this->error = 'Message is empty';
            return;
        }

        $result = Notifier::send($message);
        if (isset($result['success'])) {
            $this->response = array('success' => true, 'results' => $result['results']);
        } else {
            $this->error = isset($result['error']) ? $result['error'] : 'Failed to send notification';
        }
    }

    public function route()
    {
        switch ($this->request->action) {
            case 'getSettings':
                $this->getSettings();
                break;
            case 'saveSettings':
                $this->saveSettings();
                break;
            case 'testTelegram':
                $this->testTelegram();
                break;
            case 'testDiscord':
                $this->testDiscord();
                break;
            case 'sendMessage':
                $this->sendMessage();
                break;
            default:
                $this->error = "Unknown action: " . $this->request->action;
        }
    }
}
