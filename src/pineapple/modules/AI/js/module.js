registerController('AIController', ['$api', '$scope', '$timeout', '$interval', function($api, $scope, $timeout, $interval) {

    $scope.settings = {
        provider: 'openai',
        baseUrl: 'https://api.openai.com/v1',
        model: 'gpt-4o-mini',
        apiKey: '',
        temperature: 0.7,
        systemPrompt: ''
    };
    $scope.settingsLoaded = false;
    $scope.settingsSaved = false;
    $scope.testing = false;
    $scope.testResult = '';
    $scope.providers = [
        { value: 'openai',    label: 'OpenAI / OpenAI-compatible (OpenAI, Groq, OpenRouter, Ollama, LM Studio...)' },
        { value: 'deepseek',  label: 'DeepSeek' },
        { value: 'anthropic', label: 'Anthropic Claude' },
        { value: 'gemini',    label: 'Google Gemini' }
    ];

    // Auto-fill DeepSeek endpoint/model when selected as the provider
    $scope.onProviderChange = function() {
        if ($scope.settings.provider === 'deepseek') {
            $scope.settings.baseUrl = 'https://api.deepseek.com';
            $scope.settings.model = 'deepseek-chat';
        } else if ($scope.settings.provider === 'anthropic') {
            $scope.settings.baseUrl = 'https://api.anthropic.com';
            $scope.settings.model = 'claude-3-5-sonnet-latest';
        } else if ($scope.settings.provider === 'gemini') {
            $scope.settings.baseUrl = 'https://generativelanguage.googleapis.com';
            $scope.settings.model = 'gemini-1.5-pro';
        }
    };

    // ------- settings -------
    $scope.getSettings = function() {
        $api.request({ module: 'AI', action: 'getSettings' }, function(response) {
            if (response.success === true) {
                $scope.settings = response.settings;
                $scope.settingsLoaded = true;
            }
        });
    };

    $scope.saveSettings = function() {
        $api.request({ module: 'AI', action: 'saveSettings', settings: $scope.settings }, function(response) {
            if (response.success === true) {
                $scope.settingsSaved = true;
                $timeout(function() { $scope.settingsSaved = false; }, 2000);
            }
        });
    };

    $scope.testConnection = function() {
        $scope.testing = true;
        $scope.testResult = '';
        $api.request({ module: 'AI', action: 'testConnection', settings: $scope.settings }, function(response) {
            $scope.testing = false;
            if (response.success === true) {
                $scope.testResult = 'Connection OK. Model replied: ' + response.reply;
            } else {
                $scope.testResult = 'ERROR: ' + (response.error || 'unknown');
            }
        });
    };

    // ------- generic task runner -------
    $scope.busy = false;
    $scope.output = '';
    $scope.error = '';
    $scope.poll = null;

    $scope.runTask = function(feature, input) {
        $scope.cancelPoll();
        $scope.busy = true;
        $scope.output = '';
        $scope.error = '';
        $api.request({ module: 'AI', action: 'startTask', feature: feature, input: input || '' }, function(response) {
            if (response.success === true && response.taskId) {
                $scope.pollTask(response.taskId);
            } else {
                $scope.busy = false;
                $scope.error = response.error || 'Could not start AI task';
            }
        });
    };

    $scope.pollTask = function(taskId) {
        $scope.poll = $interval(function() {
            $api.request({ module: 'AI', action: 'getTaskResult', taskId: taskId }, function(response) {
                if (response.running === true) {
                    return;
                }
                $scope.cancelPoll();
                $scope.busy = false;
                if (response.error) {
                    $scope.error = response.error;
                } else {
                    $scope.output = response.text;
                }
            });
        }, 2500);
    };

    $scope.cancelPoll = function() {
        if ($scope.poll) {
            $interval.cancel($scope.poll);
            $scope.poll = null;
        }
    };

    // ------- feature shortcuts -------
    $scope.inputs = {
        captive_portal: '',
        ssid_pool: '',
        chat: '',
        module_goal: ''
    };

    $scope.runHandshakeAdvisor = function() { $scope.runTask('handshake_advisor'); };
    $scope.runTargetScoring  = function() { $scope.runTask('target_scoring'); };
    $scope.runCaptivePortal  = function() { $scope.runTask('captive_portal', $scope.inputs.captive_portal); };
    $scope.runSSIDPool       = function() { $scope.runTask('ssid_pool', $scope.inputs.ssid_pool); };
    $scope.runNicknamer      = function() { $scope.runTask('device_nicknamer'); };
    $scope.runAnomaly        = function() { $scope.runTask('anomaly_detector'); };
    $scope.runChat           = function() { $scope.runTask('chat', $scope.inputs.chat); };
    $scope.runModuleSuggest  = function() { $scope.runTask('module_suggester', $scope.inputs.module_goal); };
    $scope.runThreatIntel    = function() { $scope.runTask('threat_intel'); };
    $scope.runHoneypotTuner  = function() { $scope.runTask('honeypot_tuner'); };
    $scope.runWPARisk        = function() { $scope.runTask('wpa_risk'); };

    // ------- apply outputs -------
    $scope.applyPortal = function() {
        if (!$scope.output) { return; }
        $api.request({ module: 'Configuration', action: 'saveLandingPage', landingPageData: $scope.output }, function(response) {
            $scope.error = response.success === true ? '' : (response.error || 'Could not save landing page');
            if (response.success === true) {
                $scope.output = 'Landing page saved. Enable it in Configuration -> Landing Page if not already enabled.\n\n' + $scope.output;
            }
        });
    };

    $scope.applyPool = function() {
        var ssids = $scope.output.split('\n').map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0 && s.length <= 32; });
        if (ssids.length === 0) { $scope.error = 'No valid SSIDs found in output.'; return; }
        $api.request({ module: 'PineAP', action: 'addSSIDs', ssids: ssids }, function(response) {
            $scope.error = response.success === true ? '' : (response.error || 'Could not add SSIDs');
            if (response.success === true) {
                $scope.output = 'Added ' + ssids.length + ' SSIDs to the PineAP pool.\n\n' + $scope.output;
            }
        });
    };

    $scope.getSettings();
}]);
