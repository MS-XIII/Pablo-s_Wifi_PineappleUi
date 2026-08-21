registerController('NotifyController', ['$api', '$scope', '$timeout', function($api, $scope, $timeout) {

    $scope.settings = {
        telegramEnabled: false,
        telegramToken: '',
        telegramChatId: '',
        discordEnabled: false,
        discordWebhook: ''
    };
    $scope.loaded = false;
    $scope.saved = false;
    $scope.sending = false;
    $scope.message = '';
    $scope.sendResult = '';

    $scope.getSettings = function() {
        $api.request({ module: 'Notify', action: 'getSettings' }, function(response) {
            if (response.success === true) {
                $scope.settings = response.settings;
                $scope.loaded = true;
            }
        });
    };

    $scope.saveSettings = function() {
        $api.request({ module: 'Notify', action: 'saveSettings', settings: $scope.settings }, function(response) {
            if (response.success === true) {
                $scope.saved = true;
                $timeout(function() { $scope.saved = false; }, 2000);
            }
        });
    };

    $scope.testTelegram = function() {
        $scope.sending = true;
        $scope.sendResult = '';
        $api.request({ module: 'Notify', action: 'testTelegram', settings: $scope.settings }, function(response) {
            $scope.sending = false;
            $scope.sendResult = response.success === true ? 'Telegram test message sent.' : ('ERROR: ' + response.error);
        });
    };

    $scope.testDiscord = function() {
        $scope.sending = true;
        $scope.sendResult = '';
        $api.request({ module: 'Notify', action: 'testDiscord', settings: $scope.settings }, function(response) {
            $scope.sending = false;
            $scope.sendResult = response.success === true ? 'Discord test message sent.' : ('ERROR: ' + response.error);
        });
    };

    $scope.sendMessage = function() {
        $scope.sending = true;
        $scope.sendResult = '';
        $api.request({ module: 'Notify', action: 'sendMessage', message: $scope.message }, function(response) {
            $scope.sending = false;
            if (response.success === true) {
                $scope.sendResult = 'Message sent to enabled channels.';
                $scope.message = '';
            } else {
                $scope.sendResult = 'ERROR: ' + response.error;
            }
        });
    };

    $scope.getSettings();
}]);
