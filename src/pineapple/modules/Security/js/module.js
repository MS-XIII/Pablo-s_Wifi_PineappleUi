registerController('SecurityController', ['$api', '$scope', '$timeout', '$interval', function($api, $scope, $timeout, $interval) {

    $scope.aps = [];
    $scope.clients = [];
    $scope.ownAPs = [];
    $scope.rogueAPs = [];
    $scope.evilTwins = [];
    $scope.loaded = false;
    $scope.scanError = '';

    // -------- data --------
    $scope.getScanData = function() {
        $api.request({ module: 'Security', action: 'getScanData' }, function(response) {
            if (response.success === true) {
                $scope.aps = response.aps || [];
                $scope.clients = response.clients || [];
                $scope.ownAPs = response.ownAPs || [];
                $scope.loaded = true;
                $scope.analyze();
            } else {
                $scope.scanError = response.error || 'Could not load scan data';
            }
        });
    };

    // -------- analysis --------
    $scope.analyze = function() {
        $scope.detectRogueAPs();
        $scope.detectEvilTwins();
    };

    // Rogue AP: our own SSID broadcast by a DIFFERENT BSSID
    $scope.detectRogueAPs = function() {
        $scope.rogueAPs = [];
        var ownSsids = {};
        angular.forEach($scope.ownAPs, function(ap) {
            if (ap.ssid && ap.ssid !== '') {
                ownSsids[ap.ssid.toLowerCase()] = ap.bssid ? ap.bssid.toLowerCase() : null;
            }
        });

        angular.forEach($scope.aps, function(ap) {
            if (!ap.ssid) {
                return;
            }
            var ssidLower = ap.ssid.toLowerCase();
            if (ownSsids[ssidLower] !== undefined) {
                var ownBssid = ownSsids[ssidLower];
                var apBssid = ap.bssid ? ap.bssid.toLowerCase() : '';
                // different BSSID (or ours unknown) => possible rogue
                if (!ownBssid || apBssid !== ownBssid) {
                    $scope.rogueAPs.push(ap);
                }
            }
        });
    };

    // Evil twin: same SSID seen from multiple BSSIDs in the same scan
    $scope.detectEvilTwins = function() {
        $scope.evilTwins = [];
        var groups = {};
        angular.forEach($scope.aps, function(ap) {
            if (!ap.ssid || ap.ssid === '') {
                return;
            }
            var key = ap.ssid.toLowerCase();
            if (!groups[key]) {
                groups[key] = { ssid: ap.ssid, aps: [] };
            }
            groups[key].aps.push(ap);
        });

        angular.forEach(groups, function(group) {
            var bssids = {};
            angular.forEach(group.aps, function(ap) {
                if (ap.bssid) {
                    bssids[ap.bssid.toLowerCase()] = true;
                }
            });
            if (Object.keys(bssids).length > 1) {
                group.aps.sort(function(a, b) { return (a.signal || 0) - (b.signal || 0); });
                $scope.evilTwins.push(group);
            }
        });
    };

    $scope.sendAlert = function(message) {
        $api.request({ module: 'Security', action: 'sendAlert', message: message }, function(response) {
            // notifications were pushed; nothing else needed
        });
    };

    // -------- deauth attack detector --------
    $scope.deauthRunning = false;
    $scope.deauthDuration = 15;
    $scope.deauthFrames = 0;
    $scope.deauthSources = [];
    $scope.deauthAlerted = false;
    $scope.deauthThreshold = 50;

    $scope.startDeauthScan = function() {
        $scope.deauthRunning = true;
        $scope.deauthAlerted = false;
        $scope.deauthFrames = 0;
        $scope.deauthSources = [];
        $api.request({ module: 'Security', action: 'startDeauthScan', duration: $scope.deauthDuration }, function(response) {
            if (response.success === true) {
                $scope.pollDeauthScan();
            } else {
                $scope.deauthRunning = false;
            }
        });
    };

    $scope.pollDeauthScan = function() {
        $scope.deauthPoll = $interval(function() {
            $api.request({ module: 'Security', action: 'getDeauthScanStatus' }, function(response) {
                if (response.running === true) {
                    return;
                }
                $interval.cancel($scope.deauthPoll);
                $scope.deauthRunning = false;
                $scope.deauthFrames = response.frames || 0;
                $scope.deauthSources = response.sources || [];

                if ($scope.deauthFrames >= $scope.deauthThreshold && !$scope.deauthAlerted) {
                    $scope.deauthAlerted = true;
                    $scope.sendAlert('Possible deauth attack: ' + $scope.deauthFrames + ' deauth/disassoc frames captured on ' + $scope.deauthDuration + 's scan. Top source: ' + ($scope.deauthSources[0] ? $scope.deauthSources[0].mac : 'unknown'));
                }
            });
        }, 2500);
    };

    // -------- vendor fingerprinting (#24) --------
    $scope.devices = [];
    $scope.vendorMap = {};

    $scope.getFingerprintData = function() {
        $api.request({ module: 'Security', action: 'getFingerprintData' }, function(response) {
            if (response.success === true) {
                $scope.devices = response.devices || [];
                $scope.lookupVendors();
            }
        });
    };

    $scope.lookupVendors = function() {
        $scope.vendorMap = {};
        angular.forEach($scope.devices, function(device) {
            if (!$api.ouiPresent()) {
                $scope.vendorMap[device.mac] = 'OUI db not loaded';
                return;
            }
            $api.lookupOUI(device.mac, function(vendor) {
                $scope.vendorMap[device.mac] = vendor;
            });
        });
    };

    $scope.getScanData();
    $scope.getFingerprintData();

    $scope.$on('$destroy', function() {
        if ($scope.deauthPoll) {
            $interval.cancel($scope.deauthPoll);
        }
    });
}]);
