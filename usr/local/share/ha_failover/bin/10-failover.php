#!/usr/local/bin/php
<?php

/*
 * OPNsense HA Failover Script for Single WAN IP (Hardened Version)
 * Version: 15.6 - Final Production Version with Structured Logging
 * - All logging is now in structured JSON format for modern observability.
 * - Added comprehensive PHPDoc blocks to all classes and methods.
 * - Represents the final, hardened, and documented production-ready version.
 */
declare(strict_types=1);

require_once '/usr/local/etc/inc/config.inc';
require_once '/usr/local/etc/inc/interfaces.inc';
require_once '/usr/local/etc/inc/util.inc';
require_once '/usr/local/etc/inc/system.inc';
require_once __DIR__ . '/../inc/failover.inc';


/**
 * Manages the high-availability failover process based on CARP events.
 * This class orchestrates network configuration changes, service management, and health checks.
 * @package OPNsense\HA
 */
final class FailoverManager
{
    private const LOCK_FILE = '/tmp/carp_failover.lock';
    private const STATE_FILE = '/tmp/carp_failover.state';
    private const FAILURE_STATE_FILE = '/tmp/carp_failover.failures';
    private const CONFIG_FILE = '/usr/local/share/ha_failover/conf/ha_failover.conf';
    private const LOG_IDENT = 'ha_failover';
    private const MAX_CONSECUTIVE_FAILURES = 3;
    private const FAILURE_COOLDOWN = 900;

    private $lockHandle;
    private bool $isDryRun = false;

    /**
     * FailoverManager constructor.
     * @param Backend $backend OPNsense backend instance for service control.
     * @param Config $config OPNsense global configuration instance.
     * @param SettingsDTO $settings Validated configuration object.
     */
    private function __construct(
        private readonly Backend $backend,
        private readonly Config $config,
        private readonly SettingsDTO $settings
    ) {
        openlog(self::LOG_IDENT, LOG_PID | LOG_NDELAY, LOG_LOCAL4);
    }

    /**
     * Factory method to create and run a FailoverManager instance.
     * @param array $argv The command-line arguments.
     * @return int Exit code (0 for success, 1 for failure).
     */
    public static function createAndRun(array $argv): int
    {
        try {
            $manager = self::create($argv);
            if ($manager === null) return 1;
            return $manager->run($argv);
        } catch (\Exception $e) {
            // Use structured log for the final catch-all
            $logData = ['timestamp' => date('c'), 'event' => 'critical_error_initialization', 'pid' => getmypid(), 'context' => ['error' => $e->getMessage()]];
            syslog(LOG_CRIT, json_encode($logData));
            return 1;
        }
    }

    /**
     * Creates and validates a FailoverManager instance.
     * @param array $argv Command-line arguments.
     * @return self|null A configured instance or null on failure.
     */
    private static function create(array $argv): ?self
    {
        if (!file_exists(self::CONFIG_FILE) || !is_readable(self::CONFIG_FILE)) {
            syslog(LOG_CRIT, json_encode(['event' => 'config_not_found', 'path' => self::CONFIG_FILE]));
            return null;
        }
        if (substr(sprintf('%o', fileperms(self::CONFIG_FILE)), -3) !== '600') {
            syslog(LOG_ERR, json_encode(['event' => 'config_insecure_permissions', 'path' => self::CONFIG_FILE]));
            return null;
        }

        $json = json_decode(file_get_contents(self::CONFIG_FILE), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            syslog(LOG_CRIT, json_encode(['event' => 'config_invalid_json', 'error' => json_last_error_msg()]));
            return null;
        }

        try {
            $settings = new SettingsDTO($json);
        } catch (HAConfigurationException $e) {
            syslog(LOG_ERR, json_encode(['event' => 'config_validation_failed', 'error' => $e->getMessage()]));
            return null;
        }

        $manager = new self(new Backend(), Config::getInstance(), $settings);
        $manager->isDryRun = isset($argv[3]) && $argv[3] === 'dry-run';

        if (!$manager->acquireLock()) return null;
        $manager->setupShutdownHandler();
        return $manager;
    }

    /**
     * The main execution logic for the failover script.
     * @param array $argv The command-line arguments.
     * @return int Exit code.
     */
    public function run(array $argv): int
    {
        $type = $argv[2] ?? '';
        $eventStatus = CarpStatus::tryFrom($type);
        if ($eventStatus === null) return 0;

        [$scriptState, $lastTimestamp] = $this->readStateFile();

        if ($eventStatus === CarpStatus::BACKUP) {
            $this->resetFailureCount();
        }

        if ($eventStatus === $scriptState) {
            if ($eventStatus === CarpStatus::MASTER && (time() - $lastTimestamp > $this->settings->eventCooldownPeriod)) {
                $this->structuredLog('redundant_master_event', ['cooldown_period' => $this->settings->eventCooldownPeriod], LOG_NOTICE);
                if (!$this->healthCheckWithRetries()) {
                     $this->recordFailure();
                } else {
                     $this->writeStateFile(CarpStatus::MASTER);
                }
            }
            return 0;
        }

        if ($eventStatus === CarpStatus::MASTER && $this->shouldSkipTransition()) {
            $this->structuredLog('circuit_breaker_tripped', ['max_failures' => self::MAX_CONSECUTIVE_FAILURES], LOG_CRIT);
            return 1;
        }

        $this->structuredLog('state_change_detected', ['from' => $scriptState->value, 'to' => $eventStatus->value]);
        $success = match ($eventStatus) {
            CarpStatus::MASTER => $this->handleMasterTransition(),
            CarpStatus::BACKUP => $this->handleBackupTransition(),
        };

        if ($success) {
            $this->writeStateFile($eventStatus);
            if ($eventStatus === CarpStatus::MASTER) $this->resetFailureCount();
        } else {
            if ($eventStatus === CarpStatus::MASTER) $this->recordFailure();
        }

        $this->structuredLog('transition_summary', ['to_state' => $eventStatus->value, 'success' => $success], $success ? LOG_NOTICE : LOG_CRIT);
        return $success ? 0 : 1;
    }

    /**
     * Creates a timestamped backup of the current configuration.
     * @return string The path to the backup file.
     */
    private function createConfigBackup(): string
    {
        $backup_path = "/tmp/config_backup_" . time() . ".xml";
        if (!$this->isDryRun) {
            copy("/conf/config.xml", $backup_path);
        }
        $this->structuredLog('config_backup_created', ['path' => $backup_path]);
        return $backup_path;
    }

    /**
     * Cleans up old configuration backups, keeping the 5 most recent.
     */
    private function cleanupOldBackups(): void
    {
        $backups = glob("/tmp/config_backup_*.xml");
        if (count($backups) > 5) {
            sort($backups);
            $to_delete = array_slice($backups, 0, -5);
            foreach ($to_delete as $old_backup) {
                if (!$this->isDryRun) {
                    unlink($old_backup);
                }
                $this->structuredLog('old_backup_cleaned', ['file' => basename($old_backup)], LOG_DEBUG);
            }
        }
    }

    /**
     * Handles the transition to the MASTER state.
     * @return bool True on success, false on failure.
     */
    private function handleMasterTransition(): bool
    {
        $this->structuredLog('master_transition_start');
        if ($this->isDryRun) return true;

        $this->cleanupOldBackups();
        $backup_path = $this->createConfigBackup();

        $this->config->forceReload();
        $configArray = $this->config->toArray(listtags());

        if ($this->settings->wanMode === 'dhcp') {
            $configArray['interfaces'][$this->settings->wanInterfaceKey]['ipaddr'] = 'dhcp';
            unset($configArray['interfaces'][$this->settings->wanInterfaceKey]['subnet']);
        } else {
            $configArray['interfaces'][$this->settings->wanInterfaceKey]['ipaddr'] = $this->settings->wanIpv4;
            $configArray['interfaces'][$this->settings->wanInterfaceKey]['subnet'] = $this->settings->wanSubnetV4;
        }
        $configArray['interfaces'][$this->settings->wanInterfaceKey]['enable'] = true;
        $configArray['interfaces'][$this->settings->wanInterfaceKey]['gateway'] = $this->settings->wanGatewayName;
        if (!empty($this->settings->tunnelInterfaceKey)) {
            $configArray['interfaces'][$this->settings->tunnelInterfaceKey]['enable'] = true;
        }

        try {
            if (!$this->applyConfigurationWithRetry('HA Failover: Activating MASTER state', $configArray)) {
                $this->structuredLog('config_apply_failed', ['backup_path' => $backup_path], LOG_CRIT);
                return false;
            }
        } catch (HAConfigurationException $e) {
            $this->structuredLog('config_apply_error', ['error' => $e->getMessage(), 'backup_path' => $backup_path], LOG_CRIT);
            return false;
        }

        sleep($this->settings->masterTransitionDelay);

        $this->controlServices('restart', $this->settings->coreServices);
        $this->controlServices('restart', $this->settings->standardServices);

        if ($this->settings->wanMode === 'dhcp') {
            $this->structuredLog('dhcp_lease_wait', ['delay' => 15]);
            sleep(15);
        }

        if (!$this->healthCheckWithRetries()) {
             $this->structuredLog('health_check_failed_all_retries', [], LOG_CRIT);
             return false;
        }

        $this->structuredLog('master_transition_complete');
        return true;
    }

    /**
     * Handles the transition to the BACKUP state.
     * @return bool True on success, false on failure.
     */
    private function handleBackupTransition(): bool
    {
        $this->structuredLog('backup_transition_start');
        if ($this->isDryRun) return true;

        $this->controlServices('stop', $this->settings->standardServices);
        $this->controlServices('stop', $this->settings->coreServices);

        killbypid("/var/run/dpinger_{$this->settings->wanGatewayName}.pid");

        $this->config->forceReload();
        $configArray = $this->config->toArray(listtags());
        unset($configArray['interfaces'][$this->settings->wanInterfaceKey]['enable']);
        $configArray['interfaces'][$this->settings->wanInterfaceKey]['ipaddr'] = 'none';
        unset($configArray['interfaces'][$this->settings->wanInterfaceKey]['gateway']);
        if (!empty($this->settings->tunnelInterfaceKey)) {
            unset($configArray['interfaces'][$this->settings->tunnelInterfaceKey]['enable']);
        }

        if (!$this->applyConfigurationWithRetry('HA Failover: Deactivating to BACKUP state', $configArray)) return false;

        $this->structuredLog('backup_transition_complete');
        return true;
    }

    /**
     * Applies a new configuration with a retry mechanism for transient errors.
     * @param string $description Description of the configuration change.
     * @param array $configArray The new configuration array.
     * @param int $maxRetries Maximum number of retry attempts.
     * @return bool True on success.
     * @throws HAConfigurationException On non-recoverable config errors.
     * @throws HANetworkException On persistent network errors after retries.
     */
    private function applyConfigurationWithRetry(string $description, array $configArray, int $maxRetries = 5): bool
    {
        $baseDelay = 2;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                if ($this->applyConfiguration($description, $configArray)) return true;
             } catch (HAConfigurationException $e) {
                // Fail fast: Don't retry configuration errors as they are not transient.
                throw $e;
            } catch (HANetworkException $e) {
                $this->structuredLog('network_error_retry', ['attempt' => $attempt, 'error' => $e->getMessage()], LOG_WARNING);
                if ($attempt === $maxRetries) throw $e;
            }

            $delay = min($baseDelay * pow(2, $attempt - 1), 30);
            $this->structuredLog('config_apply_retry', ['attempt' => $attempt, 'delay' => $delay]);
            if (!$this->isDryRun) sleep($delay);
        }
        $this->structuredLog('config_apply_failed_all_retries', ['max_retries' => $maxRetries], LOG_ERR);
        return false;
    }

    /**
     * Applies a new configuration to the system.
     * @param string $description Description for the configuration history.
     * @param array $configArray The new configuration array.
     * @return bool True on success.
     * @throws HAConfigurationException if writing the config fails.
     * @throws HANetworkException if reconfiguring interfaces fails.
     */
    private function applyConfiguration(string $description, array $configArray): bool
    {
        $this->config->lock();
        try {
            $this->config->fromArray($configArray);
            if (write_config($description) === false) {
                $last_error = Config::getInstance()->getLastError();
                throw new HAConfigurationException("Failed to write config: " . ($last_error ?? 'Unknown error'));
            }
        } finally {
            $this->config->unlock();
        }
        try {
            $this->backend->configdRun("interface all reconfigure");
        } catch (\Exception $e) {
            throw new HANetworkException("Error during interface reconfigure: " . $e->getMessage());
        }
        return true;
    }

    /**
     * Verifies if a service is in the expected running or stopped state.
     * @param string $serviceName The name of the service.
     * @param bool $shouldBeRunning True if the service should be running, false otherwise.
     * @return bool True if the service is in the expected state within the timeout.
     */
    private function verifyServiceState(string $serviceName, bool $shouldBeRunning): bool
    {
        $all_services = array_merge($this->settings->coreServices, $this->settings->standardServices);
        $pid_file = null;
        foreach ($all_services as $service) {
            if ($service['name'] === $serviceName) {
                $pid_file = $service['pid_file'] ?? null;
                break;
            }
        }

        if (!$pid_file) {
             $this->structuredLog('service_verify_skipped', ['service' => $serviceName, 'reason' => 'no_pid_file'], LOG_WARNING);
             return true;
        }

        $wait = $this->settings->serviceVerifyTimeout;
        while ($wait > 0) {
            $isRunning = isvalidpid($pid_file);
            if ($isRunning === $shouldBeRunning) {
                return true;
            }
            sleep(1);
            $wait--;
        }
        return false;
    }

    /**
     * Starts or stops a list of services and verifies their state.
     * @param string $action The action to perform ('start', 'stop', 'restart').
     * @param array $services The list of services to control.
     * @throws HAServiceException if controlling a service fails.
     */
    private function controlServices(string $action, array $services): void
    {
        $this->structuredLog('service_control_start', ['action' => $action, 'service_count' => count($services)]);
        foreach ($services as $service) {
            if (empty($service['name'])) continue;
            try {
                $this->backend->configdRun("service {$action} " . escapeshellarg($service['name']));

                $shouldBeRunning = in_array($action, ['start', 'restart']);
                if (!$this->verifyServiceState($service['name'], $shouldBeRunning)) {
                    $this->structuredLog('service_verify_failed', ['service' => $service['name'], 'action' => $action], LOG_ERR);
                } else {
                    $this->structuredLog('service_verify_success', ['service' => $service['name'], 'action' => $action], LOG_INFO);
                }
            } catch (\Exception $e) {
                throw new HAServiceException("Exception with service '{$service['name']}': " . $e->getMessage());
            }
        }
    }

    /**
     * Performs a multi-stage health check with retries to validate the MASTER state.
     * @return bool True if the system is healthy, false otherwise.
     */
    private function healthCheckWithRetries(): bool
    {
        for ($i = 1; $i <= $this->settings->healthCheckRetries; $i++) {
            if ($this->healthCheckMasterState()) {
                return true;
            }
            if ($i < $this->settings->healthCheckRetries) {
                $this->structuredLog('health_check_retry', ['attempt' => $i, 'delay' => $this->settings->healthCheckRetryDelay], LOG_NOTICE);
                sleep($this->settings->healthCheckRetryDelay);
            }
        }
        return false;
    }

    /**
     * Performs a single, comprehensive health check of the system.
     * @return bool True if checks pass according to the configured policy.
     */
    private function healthCheckMasterState(): bool
    {
        if ($this->isDryRun) return true;

        $wan_ip = get_interface_ip($this->settings->wanInterfaceKey);
        if ($this->settings->wanMode === 'static' && $wan_ip !== $this->settings->wanIpv4) {
            $this->structuredLog('health_check_failed', ['reason' => 'wan_ip_mismatch', 'expected' => $this->settings->wanIpv4, 'actual' => $wan_ip], LOG_ERR);
            return false;
        } elseif ($this->settings->wanMode === 'dhcp' && (empty($wan_ip) || is_private_ipv4($wan_ip))) {
            $this->structuredLog('health_check_failed', ['reason' => 'invalid_dhcp_lease', 'current_ip' => $wan_ip ?: 'None'], LOG_ERR);
            return false;
        }

        $local_ok = !$this->settings->localHealthCheckTarget;
        if ($this->settings->localHealthCheckTarget) {
            if (mwexec('/sbin/ping -c 1 -W 1 ' . escapeshellarg($this->settings->localHealthCheckTarget), true) === 0) {
                $local_ok = true;
            }
        }

        $external_v4_ok = false;
        foreach ($this->settings->healthCheckTargetsV4 as $target) {
            if (mwexec('/sbin/ping -c 1 -W ' . escapeshellarg((string)$this->settings->pingTimeout) . ' ' . escapeshellarg($target), true) === 0) {
                $external_v4_ok = true;
                break;
            }
        }

        $external_v6_ok = false;
        if (!empty($this->settings->tunnelInterfaceKey) && !empty($this->settings->healthCheckTargetsV6)) {
            if (empty(get_interface_ipv6($this->settings->tunnelInterfaceKey))) {
                $this->structuredLog('health_check_warn', ['reason' => 'no_ipv6_on_tunnel', 'interface' => $this->settings->tunnelInterfaceKey], LOG_WARNING);
            } else {
                foreach ($this->settings->healthCheckTargetsV6 as $target) {
                    if (mwexec("ping6 -c 1 -W " . escapeshellarg((string)$this->settings->pingTimeout) . " " . escapeshellarg($target), true) === 0) {
                        $external_v6_ok = true;
                        break;
                    }
                }
            }
        }

        $external_ok = $external_v4_ok || $external_v6_ok;

        $results = ['local_ok' => $local_ok, 'external_v4_ok' => $external_v4_ok, 'external_v6_ok' => $external_v6_ok];
        $this->structuredLog('health_check_results', $results, LOG_INFO);
        
        if ($this->settings->requireExternalConnectivity) {
            if ($local_ok && $external_ok) return true;
        } else {
            if ($local_ok || $external_ok) return true;
        }

        return false;
    }

    /**
     * @return CarpStatus Returns the current system CARP status by inspecting ifconfig output.
     */
    private function getCurrentSystemCarpStatus(): CarpStatus
    {
        exec('/sbin/ifconfig 2>&1 | grep "carp: MASTER"', $output);
        return !empty($output) ? CarpStatus::MASTER : CarpStatus::BACKUP;
    }

    /**
     * @return array Initializes the state file if it doesn't exist.
     */
    private function initializeState(): array
    {
        $status = $this->getCurrentSystemCarpStatus();
        $this->structuredLog('state_file_init', ['initial_status' => $status->value]);
        $this->writeStateFile($status);
        return [$status, time()];
    }

    /**
     * @return array Reads the current status and timestamp from the state file.
     */
    private function readStateFile(): array
    {
        $content = @file_get_contents(self::STATE_FILE);
        if ($content === false || !preg_match('/^(MASTER|BACKUP):(\d+)$/', $content, $matches)) {
            return $this->initializeState();
        }
        return [CarpStatus::from($matches[1]), (int)$matches[2]];
    }

    /**
     * @param CarpStatus $status Writes the new status to the state file.
     */
    private function writeStateFile(CarpStatus $status): void
    {
        if (!$this->isDryRun) file_put_contents(self::STATE_FILE, "{$status->value}:" . time());
    }

    /**
     * @return bool Acquires an exclusive lock to prevent concurrent execution.
     */
    private function acquireLock(): bool
    {
        $this->lockHandle = fopen(self::LOCK_FILE, 'c');
        if ($this->lockHandle === false) {
            $this->structuredLog('lock_acquire_failed', ['reason' => 'cannot_open_file'], LOG_ERR);
            return false;
        }
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            $this->structuredLog('lock_acquire_failed', ['reason' => 'already_locked'], LOG_WARNING);
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }
        ftruncate($this->lockHandle, 0);
        fwrite($this->lockHandle, (string)getmypid());
        fflush($this->lockHandle);
        return true;
    }

    /**
     * Releases the script lock.
     */
    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
            @unlink(self::LOCK_FILE);
        }
    }

    /**
     * Registers a shutdown handler to ensure the lock is always released.
     */
    private function setupShutdownHandler(): void
    {
        register_shutdown_function([$this, 'releaseLock']);
    }

    /**
     * @return array Gets the current failure count and timestamp.
     */
    private function getFailureState(): array
    {
        $content = @file_get_contents(self::FAILURE_STATE_FILE);
        return $content ? (json_decode($content, true) ?? ['count' => 0, 'timestamp' => 0]) : ['count' => 0, 'timestamp' => 0];
    }
    /**
     * @param int $count The new failure count.
     * @param int $timestamp The timestamp of the failure.
     */
    private function writeFailureState(int $count, int $timestamp): void
    {
        if (!$this->isDryRun) file_put_contents(self::FAILURE_STATE_FILE, json_encode(['count' => $count, 'timestamp' => $timestamp]));
    }
    /**
     * Increments the failure counter.
     */
    private function recordFailure(): void
    {
        $fs = $this->getFailureState();
        $this->writeFailureState($fs['count'] + 1, time());
        $this->structuredLog('failover_failure_recorded', ['new_count' => $fs['count'] + 1], LOG_WARNING);
    }
    /**
     * Resets the failure counter.
     */
    private function resetFailureCount(): void
    {
        if (file_exists(self::FAILURE_STATE_FILE)) {
            $this->structuredLog('failover_failure_reset');
            @unlink(self::FAILURE_STATE_FILE);
        }
    }
    /**
     * @return bool Determines if the circuit breaker should prevent a transition.
     */
    private function shouldSkipTransition(): bool
    {
        $fs = $this->getFailureState();
        if ((time() - $fs['timestamp']) > self::FAILURE_COOLDOWN) {
            $this->resetFailureCount();
            return false;
        }
        return $fs['count'] >= self::MAX_CONSECUTIVE_FAILURES;
    }

    /**
     * Writes a structured JSON log message to syslog.
     * @param string $event A short, machine-readable event name.
     * @param array $context Key-value pairs providing context for the event.
     * @param int $priority The syslog priority level.
     */
    private function structuredLog(string $event, array $context = [], int $priority = LOG_INFO): void
    {
        $logData = [
            'timestamp' => date('c'),
            'event' => $event,
            'pid' => getmypid(),
            'context' => $context
        ];

        $jsonLog = json_encode($logData);

        if ($this->isDryRun) {
            $level = match ($priority) {
                LOG_CRIT => 'CRITICAL',
                LOG_ERR => 'ERROR',
                LOG_WARNING => 'WARNING',
                LOG_NOTICE => 'NOTICE',
                default => 'INFO',
            };
            echo "DRY RUN [{$level}]: {$jsonLog}\n";
        } else {
            syslog($priority, $jsonLog);
        }
    }

}

exit(FailoverManager::createAndRun($argv));
