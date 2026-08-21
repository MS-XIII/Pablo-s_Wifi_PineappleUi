<?php namespace pineapple;

require_once('AIHelper.php');

class AI extends SystemModule
{
    private function generateTaskId()
    {
        return time() . '_' . rand(1000, 9999);
    }

    private function getSettings()
    {
        $this->response = array('settings' => aiGetSettings(), 'success' => true);
    }

    private function saveSettings()
    {
        $settings = (array)$this->request->settings;
        $clean = aiSaveSettings($settings);
        $this->response = array('settings' => $clean, 'success' => true);
    }

    private function testConnection()
    {
        $settings = aiGetSettings();
        if (isset($this->request->settings) && is_object($this->request->settings)) {
            $testSettings = (array)$this->request->settings;
            foreach ($testSettings as $key => $value) {
                if ($key === 'apiKey' && empty($value)) {
                    continue;
                }
                $settings[$key] = $value;
            }
        }

        if (empty($settings['apiKey'])) {
            $this->error = 'Please configure an API key first.';
            return;
        }

        $result = aiCallProvider($settings, 'You are a connectivity test.', 'Reply with exactly: OK', 20);
        if (isset($result['text'])) {
            $this->response = array('success' => true, 'reply' => $result['text']);
        } else {
            $this->error = isset($result['error']) ? $result['error'] : 'Test failed';
        }
    }

    private function startTask()
    {
        $feature = isset($this->request->feature) ? $this->request->feature : 'chat';
        $input   = isset($this->request->input) ? $this->request->input : '';
        $taskId  = $this->generateTaskId();

        $task = array(
            'taskId'   => $taskId,
            'feature'  => $feature,
            'input'    => $input,
            'settings' => aiGetSettings(),
        );

        $taskFile = '/tmp/ai_task_' . $taskId . '.json';
        @unlink('/tmp/ai_result_' . $taskId . '.json');
        if (file_put_contents($taskFile, json_encode($task)) === false) {
            $this->error = 'Could not create AI task file.';
            return;
        }

        $this->execBackground('php /pineapple/modules/AI/api/ai_worker.php ' . escapeshellarg($taskId));
        $this->response = array('taskId' => $taskId, 'success' => true);
    }

    private function getTaskResult()
    {
        $taskId = isset($this->request->taskId) ? preg_replace('/[^0-9_]/', '', $this->request->taskId) : '';
        if (empty($taskId)) {
            $this->error = 'Invalid task id.';
            return;
        }

        $resultFile = '/tmp/ai_result_' . $taskId . '.json';
        if (!file_exists($resultFile)) {
            $this->response = array('running' => true, 'success' => true);
            return;
        }

        $data = json_decode(@file_get_contents($resultFile), true);
        @unlink($resultFile);
        @unlink('/tmp/ai_task_' . $taskId . '.json');

        if (is_array($data) && isset($data['error'])) {
            $this->response = array('running' => false, 'error' => $data['error'], 'success' => true);
        } elseif (is_array($data) && isset($data['text'])) {
            $this->response = array('running' => false, 'text' => $data['text'], 'success' => true);
        } else {
            $this->response = array('running' => false, 'error' => 'Worker returned no usable result.', 'success' => true);
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
            case 'testConnection':
                $this->testConnection();
                break;
            case 'startTask':
                $this->startTask();
                break;
            case 'getTaskResult':
                $this->getTaskResult();
                break;
            default:
                $this->error = "Unknown action: " . $this->request->action;
        }
    }
}
