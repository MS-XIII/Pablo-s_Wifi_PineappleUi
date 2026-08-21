registerController("ModuleManagerController", ['$api', '$scope', '$timeout', '$interval', '$templateCache', '$rootScope', function($api, $scope, $timeout, $interval, $templateCache, $rootScope){
    $rootScope.availableModules = [];
    $rootScope.installedModules = [];
    $scope.installedModule = "";
    $scope.removedModule = "";
    $scope.gotAvailableModules = false;
    $scope.connectionError = false;
    $scope.selectedModule = false;
    $scope.downloading = false;
    $scope.installing = false;
    $scope.linking = false;

    $scope.getAvailableModules = (function() {
        $scope.loading = true;
        $api.request({
            module: "ModuleManager",
            action: "getAvailableModules"
        }, function(response) {
            $scope.loading = false;
            if (response.error === undefined) {
                $rootScope.availableModules = response.availableModules;
                $scope.compareModuleLists();
                $scope.gotAvailableModules = true;
                $scope.connectionError = false;
            } else {
                $scope.connectionError = response.error;
            }
        });
    });

    $scope.getInstalledModules = (function() {
        $api.request({
            module: "ModuleManager",
            action: "getInstalledModules"
        }, function(response) {
            $rootScope.installedModules = response.installedModules;
            if ($scope.gotAvailableModules) {
                $scope.compareModuleLists();
            }
        });
    });

    $scope.compareModuleLists = (function() {
        angular.forEach($rootScope.availableModules, function(module, moduleName){
            if ($rootScope.installedModules[moduleName] === undefined){
                module['installable'] = true;
            } else if ($rootScope.availableModules[moduleName].version <= $rootScope.installedModules[moduleName].version) {
                module['installed'] = true;
            }
        });
    });

    $scope.removeModule = (function(name) {
        $api.request({
            module: 'ModuleManager',
            action: 'removeModule',
            moduleName: name
        }, function(response) {
            if (response.success === true) {
                $scope.getInstalledModules();
                $scope.removedModule = true;
                $api.reloadNavbar();
                $timeout(function(){
                    $scope.removedModule = false;
                }, 2000);
            }
        });
    });

    $scope.restoreSDcardModules = (function() {
        $api.request({
            module: 'ModuleManager',
            action: 'restoreSDcardModules'
        }, function(response) {
            if (response.restored === true) {
                $scope.restoreSDcardModules();
            } else {
                $api.reloadNavbar();
                $scope.getInstalledModules();
                $scope.linking = false;
            }
        });
    });

    // ------- store browser -------
    $scope.searchTerm = '';
    $scope.typeFilter = '';

    $scope.filteredAvailable = function() {
        var term = ($scope.searchTerm || '').toLowerCase();
        var type = $scope.typeFilter;
        var result = {};
        angular.forEach($rootScope.availableModules, function(module, name) {
            var matchType = !type || (module['type'] || '').toLowerCase() === type.toLowerCase();
            var matchTerm = !term
                || name.toLowerCase().indexOf(term) !== -1
                || (module['title'] || '').toLowerCase().indexOf(term) !== -1
                || (module['author'] || '').toLowerCase().indexOf(term) !== -1
                || (module['description'] || '').toLowerCase().indexOf(term) !== -1;
            if (matchType && matchTerm) {
                result[name] = module;
            }
        });
        return result;
    };

    $scope.getTypes = function() {
        var types = {};
        angular.forEach($rootScope.availableModules, function(module) {
            var t = module['type'] || 'Unknown';
            types[t] = true;
        });
        return Object.keys(types);
    };

    $scope.isUpdateable = function(name) {
        var module = $rootScope.availableModules[name];
        var installed = $rootScope.installedModules[name];
        return module && installed && !module['installable'] && !module['installed'];
    };

    $scope.updateableModules = function() {
        var result = [];
        angular.forEach($rootScope.availableModules, function(module, name) {
            if ($scope.isUpdateable(name)) {
                result.push(name);
            }
        });
        return result;
    };

    // ------- module auto-updater -------
    $scope.updatingAll = false;
    $scope.updateAllStatus = '';

    $scope.updateAll = (function() {
        var queue = $scope.updateableModules();
        if (queue.length === 0) {
            $scope.updateAllStatus = 'All modules are up to date.';
            return;
        }

        $scope.updatingAll = true;
        $scope.updateAllStatus = '';
        var current = 0;

        var updateNext = (function() {
            if (current >= queue.length) {
                $scope.updatingAll = false;
                $scope.updateAllStatus = 'Update complete. ' + queue.length + ' module(s) updated.';
                $scope.getInstalledModules();
                $api.reloadNavbar();
                return;
            }

            var moduleName = queue[current];
            var module = $rootScope.availableModules[moduleName];
            $scope.updateAllStatus = 'Updating ' + (module['title'] || moduleName) + ' (' + (current + 1) + '/' + queue.length + ')...';

            $api.request({
                module: 'ModuleManager',
                action: 'checkDestination',
                name: moduleName,
                size: module['size'] || 0
            }, function(response) {
                if (response.error !== undefined) {
                    current++;
                    updateNext();
                    return;
                }

                var dest = response.internal ? 'internal' : 'sd';
                $api.request({
                    module: 'ModuleManager',
                    action: 'downloadModule',
                    moduleName: moduleName,
                    destination: dest
                }, function() {
                    var downloadIval = $interval(function() {
                        $api.request({
                            module: 'ModuleManager',
                            action: 'downloadStatus',
                            moduleName: moduleName,
                            destination: dest,
                            checksum: module['checksum'] || ''
                        }, function(dlResp) {
                            if (dlResp.success === true) {
                                $interval.cancel(downloadIval);
                                $api.request({
                                    module: 'ModuleManager',
                                    action: 'installModule',
                                    moduleName: moduleName,
                                    destination: dest
                                }, function() {
                                    var installIval = $interval(function() {
                                        $api.request({
                                            module: 'ModuleManager',
                                            action: 'installStatus'
                                        }, function(instResp) {
                                            if (instResp.success === true) {
                                                $interval.cancel(installIval);
                                                current++;
                                                updateNext();
                                            }
                                        });
                                    }, 500);
                                });
                            }
                        });
                    }, 2000);
                });
            });
        });

        updateNext();
    });

    // ------- dependency checker -------
    $scope.dependencyData = {};
    $scope.checkingDeps = false;

    $scope.checkDependencies = (function() {
        $scope.checkingDeps = true;
        $api.request({
            module: 'ModuleManager',
            action: 'checkModuleDependencies'
        }, function(response) {
            $scope.checkingDeps = false;
            if (response.success === true) {
                $scope.dependencyData = response.dependencies;
            }
        });
    });

    $scope.moduleMissingDeps = function(name) {
        var data = $scope.dependencyData[name];
        return data ? data.missing : null;
    };

    $scope.getInstalledModules();
}]);
