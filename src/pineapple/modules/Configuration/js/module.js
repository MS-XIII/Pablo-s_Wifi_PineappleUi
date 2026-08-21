registerController("ConfigurationGeneralController", ['$api', '$scope', '$timeout', function($api, $scope, $timeout) {
	$scope.actionMessage = "";
	$scope.currentTimeZone = "";
	$scope.customOffset = "";
	$scope.showTimeZoneSuccess = false;
	$scope.oldPassword = "";
	$scope.newPassword = "";
	$scope.newPasswordRepeat = "";
	$scope.showPasswordSuccess = false;
	$scope.showPasswordError = false;
	$scope.device = "";
	$scope.resetMessage = "";

	$scope.haltPineapple = (function() {
		if (confirm("Are you sure you want to shutdown your WiFi Pineapple?")) {
			$api.request({
				module: "Configuration",
				action: "haltPineapple"
			}, function(response) {
				if (response.success !== undefined) {
					$scope.actionMessage = "Your WiFi Pineapple is now shutting down. Once the LED has turned off, it is safe to unplug.";
					$timeout(function(){
					    $scope.actionMessage = "";
					}, 10000);
				}
			});
		}
	});

	$scope.rebootPineapple = (function() {
		if (confirm("Are you sure you want to reboot your WiFi Pineapple?")) {
			$api.request({
				module: "Configuration",
				action: "rebootPineapple"
			}, function(response) {
				if (response.success !== undefined) {
					$scope.actionMessage = "Your WiFi Pineapple is now rebooting. You may need to reconnect once it is done.";
					$timeout(function(){
					    $scope.actionMessage = "";
					}, 10000);
				}
			});
		}
	});

	$scope.resetPineapple = (function() {
		$scope.resetMessage = "Are you sure you want to factory reset your WiFi Pineapple?\n\nThis will erase all data that has not been saved on the SD card.";

		if (confirm($scope.resetMessage)) {
			$api.request({
				module: "Configuration",
				action: "resetPineapple"
			}, function(response) {
				if (response.success !== undefined) {
					$scope.actionMessage = "Your WiFi Pineapple is now restoring to factory defaults. This can take a few minutes and you will be disconnected.";
					$timeout(function(){
					    $scope.actionMessage = "";
					}, 10000);
				}
			});
		}
	});

	$scope.changePassword = (function() {
		$api.request({
			module: 'Configuration',
			action: 'changePass',
			oldPassword: $scope.oldPassword,
			newPassword: $scope.newPassword,
			newPasswordRepeat: $scope.newPasswordRepeat
		}, function(response) {
			if (response.success === true) {
				$scope.showPasswordSuccess = true;
				$timeout(function(){
				    $scope.showPasswordSuccess = false;
				}, 2000);
			} else {
				$scope.showPasswordError = true;
				$timeout(function(){
				    $scope.showPasswordError = false;
				}, 5000);
			}
			$scope.oldPassword = "";
			$scope.newPassword = "";
			$scope.newPasswordRepeat = "";
		});
	});

	$scope.timeZones = [
		{ value: 'GMT+12', description: "(GMT-12:00) Eniwetok, Kwajalein" },
		{ value: 'GMT+11', description: "(GMT-11:00) Midway Island, Samoa" },
		{ value: 'GMT+10', description: "(GMT-10) Hawaii" },
		{ value: 'GMT+9',  description: "(GMT-9) Alaska" },
		{ value: 'GMT+8',  description: "(GMT-8) Pacific Time (US & Canada)" },
		{ value: 'GMT+7',  description: "(GMT-7) Mountain Time (US & Canada)" },
		{ value: 'GMT+6',  description: "(GMT-6) Central Time (US & Canada), Mexico City" },
		{ value: 'GMT+5',  description: "(GMT-5) Eastern Time (US & Canada), Bogota, Lima" },
		{ value: 'GMT+4',  description: "(GMT-4) Atlantic Time (Canada), Caracas, La Paz" },
		{ value: 'GMT+3',  description: "(GMT-3) Brazil, Buenos Aires, Georgetown" },
		{ value: 'GMT+2',  description: "(GMT-2) MidAtlantic" },
		{ value: 'GMT+1',  description: "(GMT-1) Azores, Cape Verde Islands" },
		{ value: 'UTC',    description: "(UTC) Western Europe Time, London, Lisbon, Casablanca"},
		{ value: 'GMT-1',  description: "(GMT+1) Brussels, Copenhagen, Madrid, Paris" },
		{ value: 'GMT-2',  description: "(GMT+2) Kaliningrad, South Africa" },
		{ value: 'GMT-3',  description: "(GMT+3) Baghdad, Riyadh, Moscow, St. Petersburg" },
		{ value: 'GMT-4',  description: "(GMT+4) Abu Dhabi, Muscat, Baku, Tbilisi" },
		{ value: 'GMT-5',  description: "(GMT+5) Ekaterinburg, Islamabad, Karachi, Tashkent" },
		{ value: 'GMT-6',  description: "(GMT+6) -Almaty, Dhaka, Colombo" },
		{ value: 'GMT-7',  description: "(GMT+7) Bangkok, Hanoi, Jakarta" },
		{ value: 'GMT-8',  description: "(GMT+8) Beijing, Perth, Singapore, Hong Kong" },
		{ value: 'GMT-9',  description: "(GMT+9) Tokyo, Seoul, Osaka, Sapporo, Yakutsk" },
		{ value: 'GMT-10', description: "(GMT+10) Eastern Australia, Guam, Vladivostok" },
		{ value: 'GMT-11', description: "(GMT+11) Magadan, Solomon Islands, New Caledonia" },
		{ value: 'GMT-12', description: "(GMT+12) Auckland, Wellington, Fiji, Kamchatka" }
	];


	$scope.getCurrentTimeZone = (function() {
		$api.request({
			module: "Configuration",
			action: "getCurrentTimeZone"
		}, function(response) {
			$scope.currentTimeZone = response.currentTimeZone;
		});
	});

	$scope.changeTimezone = (function() {
		var tmpTimeZone = $scope.selectedTimeZone.value;
		if ($scope.customOffset.trim() !== "") {
			tmpTimeZone = $scope.customOffset;
		}
		$api.request({
			module: "Configuration",
			action: "changeTimeZone",
			timeZone: tmpTimeZone
		}, function(response) {
			if (response.success !== undefined) {
				$scope.getCurrentTimeZone();
				$scope.customOffset = "";
				$scope.showTimeZoneSuccess = true;
				$timeout(function(){
					$scope.showTimeZoneSuccess = false;
				}, 2000)
			}
		});
	});

	$scope.getCurrentTimeZone();

	$api.onDeviceIdentified(function(device, scope) {
		scope.device = device;
	}, $scope);
}]);

registerController('ConfigurationLandingPageController', ['$api', '$scope', '$timeout', function($api, $scope, $timeout) {
	$scope.pageSaved = false;
	$scope.landingPage = '';
	$scope.landingPageStatus = 'Disabled';

	$api.request({
		module: 'Configuration',
		action: 'getLandingPageData'
	}, function(response) {
		$scope.landingPage = response.landingPage;
	});

	$scope.saveLandingPage = (function() {
		$api.request({
			module: 'Configuration',
			action: 'saveLandingPage',
			landingPageData: $scope.landingPage
		}, function(response) {
            if (response.success === true) {
                $scope.pageSaved = true;
                $timeout(function(){
                    $scope.pageSaved = false;
                }, 2000);
            }
		});
	});

	$scope.getLandingPageStatus = (function() {
		$api.request({
			module: 'Configuration',
			action: 'getLandingPageStatus'
		}, function(response) {
			if (response.error === undefined) {
				if (response.enabled === true) {
					$scope.landingPageStatus = 'Enabled';
				} else {
					$scope.landingPageStatus = 'Disabled';
				}
			}
		});
	});

	$scope.toggleLandingPage = (function() {
		var toggleAction = ($scope.landingPageStatus === 'Enabled') ? 'disableLandingPage' : 'enableLandingPage';
		$api.request({
			module: 'Configuration',
			action: toggleAction
		}, function(response) {
			if (response.error === undefined) {
				$scope.getLandingPageStatus();
			}
		});
	});


	$scope.getAutoStartStatus = (function() {
		$api.request({
			module: 'Configuration',
			action: 'getAutoStartStatus'
		}, function(response) {
			if (response.error === undefined) {
				if (response.enabled === true) {
					$scope.autoStartStatus = 'Enabled';
				} else {
					$scope.autoStartStatus = 'Disabled';
				}
			}
		});
	});

	$scope.toggleAutoStart = (function() {
		var toggleAction = ($scope.autoStartStatus === 'Enabled') ? 'disableAutoStart' : 'enableAutoStart';
		$api.request({
			module: 'Configuration',
			action: toggleAction
		}, function(response) {
			if (response.error === undefined) {
				$scope.getAutoStartStatus();
			}
		});
	});


	$scope.getLandingPageStatus();
	$scope.getAutoStartStatus();
}]);

registerController('TorController', ['$api', '$scope', '$timeout', '$interval', function($api, $scope, $timeout, $interval) {
    $scope.torStatus = {
        installed: false,
        running: false,
        obfs4: false,
        enabled: false
    };
    $scope.torConfig = {
        socksPort: 9050,
        bridges: '',
        useObfs4: false
    };
    $scope.torBusy = false;
    $scope.torMessage = '';
    $scope.torError = '';
    $scope.torInstallPoll = null;

    $scope.getTorStatus = function() {
        $api.request({ module: 'Configuration', action: 'getTorStatus' }, function(response) {
            if (response.success === true) {
                $scope.torStatus = response;
                if (response.installed) {
                    $scope.getTorConfig();
                }
            }
        });
    };

    $scope.getTorConfig = function() {
        $api.request({ module: 'Configuration', action: 'getTorConfig' }, function(response) {
            if (response.success === true) {
                $scope.torConfig = response.config;
            }
        });
    };

    $scope.toggleTor = function() {
        $scope.torBusy = true;
        $scope.torError = '';
        $api.request({ module: 'Configuration', action: 'setTorEnabled', enabled: $scope.torStatus.running ? false : true }, function(response) {
            $scope.torBusy = false;
            if (response.success === true) {
                $scope.getTorStatus();
            } else {
                $scope.torError = response.error || 'Could not toggle Tor';
            }
        });
    };

    $scope.saveTorConfig = function() {
        $scope.torBusy = true;
        $scope.torError = '';
        $api.request({
            module: 'Configuration',
            action: 'saveTorConfig',
            socksPort: $scope.torConfig.socksPort,
            bridges: $scope.torConfig.bridges,
            useObfs4: $scope.torConfig.useObfs4
        }, function(response) {
            $scope.torBusy = false;
            if (response.success === true) {
                $scope.torMessage = 'Tor config saved.';
                $timeout(function() { $scope.torMessage = ''; }, 3000);
            } else {
                $scope.torError = response.error || 'Could not save Tor config';
            }
        });
    };

    $scope.installTor = function() {
        $scope.torBusy = true;
        $scope.torError = '';
        $scope.torMessage = 'Installing Tor, please wait...';
        $api.request({ module: 'Configuration', action: 'installTorPackage' }, function() {
            $scope.torInstallPoll = $interval(function() {
                $api.request({ module: 'Configuration', action: 'getTorStatus' }, function(response) {
                    if (response.success === true && response.installed) {
                        $interval.cancel($scope.torInstallPoll);
                        $scope.torBusy = false;
                        $scope.torMessage = 'Tor installed.';
                        $scope.getTorStatus();
                    }
                });
            }, 3000);
        });
    };

    $scope.installObfs4 = function() {
        $scope.torBusy = true;
        $api.request({ module: 'Configuration', action: 'installObfs4Package' }, function() {
            $scope.torBusy = false;
            $scope.torMessage = 'obfs4 install started.';
            $timeout(function() { $scope.torMessage = ''; }, 3000);
        });
    };

    $scope.getTorStatus();

    $scope.$on('$destroy', function() {
        if ($scope.torInstallPoll) {
            $interval.cancel($scope.torInstallPoll);
        }
    });
}]);

registerController('ButtonScriptController', ['$api', '$scope', '$timeout', function($api, $scope, $timeout) {
	$scope.buttonScript = "";
	$scope.scriptError = '';
	$scope.scriptSaved = false;

	$scope.getButtonScript = (function() {
		$api.request({
			module: 'Configuration',
			action: 'getButtonScript'
		}, function(response) {
			if (response.error === undefined) {
				$scope.buttonScript = response.buttonScript;
			} else {
				$scope.scriptError = response.error;
			}
		});
	});
	$scope.getButtonScript();

	$scope.saveButtonScript = (function() {
		$api.request({
			module: 'Configuration',
			action: 'saveButtonScript',
			buttonScript: $scope.buttonScript
		}, function(response) {
			if (response.error === undefined) {
				$scope.scriptSaved = true;
				$timeout(function(){
                    $scope.scriptSaved = false;
                }, 2000);
			} else {
				$scope.scriptError = response.error;
			}
		});
	});
}]);