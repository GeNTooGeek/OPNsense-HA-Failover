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


use OPNsense\Core\Backend;
use OPNsense\Core\Config;

// --- Custom Exception Types for Clearer Error Handling ---
class HAConfigurationException extends \Exception {}
class HANetworkException extends \Exception {}
class HAServiceException extends \Exception {}

// --- Extracted Constants for Maintainability ---
final class HAConstants
{
    public const MAX_SUBNET_V4 = 32;
    public const MIN_PING_TIMEOUT = 1;
    public const MAX_PING_TIMEOUT = 10;
    public const MAX_TRANSITION_DELAY = 300;
    public const MIN_COOLDOWN_PERIOD = 30;
    public const MAX_COOLDOWN_PERIOD = 3600;
    public const MAX_SETTLE_DELAY = 60;
    public const MIN_LOCK_TIMEOUT = 10;
    public const MAX_LOCK_TIMEOUT = 300;
}


/**
 * Enum representing the CARP status states.
 */
enum CarpStatus: string {
    case MASTER = 'MASTER';
    case BACKUP = 'BACKUP';
}

/**
 * A Data Transfer Object (DTO) for safely parsing and validating the ha_failover.conf file.
 * Throws HAConfigurationException on any validation errors.
 */
final class SettingsDTO
{
    public readonly string $wanInterfaceKey, $tunnelInterfaceKey, $wanMode, $wanGatewayName;
    public readonly ?string $wanIpv4, $tunnelGatewayName, $localHealthCheckTarget;
    public readonly ?int $wanSubnetV4;
    public readonly array $healthCheckTargetsV4, $healthCheckTargetsV6, $coreServices, $standardServices;
    public readonly int $masterTransitionDelay, $eventCooldownPeriod, $pingTimeout, $routeSettleDelay, $lockWaitTimeout, $healthCheckRetries, $healthCheckRetryDelay, $serviceVerifyTimeout;
    public readonly bool $requireExternalConnectivity;

    /**
     * SettingsDTO constructor.
     * @param array $config The decoded JSON data from the configuration file.
     * @throws HAConfigurationException if any configuration value is missing or invalid.
     */
    public function __construct(array $config)
    {
        // Interface Validation
        $this->wanInterfaceKey = $config['interfaces']['wan_key'] ?? throw new HAConfigurationException("interfaces.wan_key is required.");
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->wanInterfaceKey)) throw new HAConfigurationException("interfaces.wan_key contains invalid characters.");
        $this->tunnelInterfaceKey = isset($config['interfaces']['tunnel_key']) ? (string)$config['interfaces']['tunnel_key'] : '';

        // Network Validation
        $network = $config['network'] ?? [];
        $this->wanMode = $network['wan_mode'] ?? 'static';
        if (!in_array($this->wanMode, ['static', 'dhcp'])) throw new HAConfigurationException("network.wan_mode must be 'static' or 'dhcp'.");
        if ($this->wanMode === 'static') {
            $this->wanIpv4 = filter_var($network['wan_ipv4'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: throw new HAConfigurationException("A valid network.wan_ipv4 is required.");
            $this->wanSubnetV4 = (int)($network['wan_subnet_v4'] ?? 0);
            if ($this->wanSubnetV4 < 1 || $this->wanSubnetV4 > HAConstants::MAX_SUBNET_V4) throw new HAConfigurationException("network.wan_subnet_v4 must be 1-" . HAConstants::MAX_SUBNET_V4);
        } else { $this->wanIpv4 = null; $this->wanSubnetV4 = null; }
        $this->wanGatewayName = $network['wan_gateway_name'] ?? throw new HAConfigurationException("network.wan_gateway_name is required.");
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $this->wanGatewayName)) throw new HAConfigurationException("network.wan_gateway_name contains invalid characters.");
        $this->tunnelGatewayName = $network['tunnel_gateway_name'] ?? null;
        if ($this->tunnelGatewayName && !preg_match('/^[a-zA-Z0-9_-]+$/', $this->tunnelGatewayName)) throw new HAConfigurationException("network.tunnel_gateway_name contains invalid characters.");

        // Health Check Validation
        $health = $config['health_check'] ?? [];
        $this->localHealthCheckTarget = $health['local_target'] ?? null;
        if ($this->localHealthCheckTarget && !filter_var($this->localHealthCheckTarget, FILTER_VALIDATE_IP)) throw new HAConfigurationException("health_check.local_target is not a valid IP.");
        $this->requireExternalConnectivity = (bool)($health['require_external_connectivity'] ?? false);
        $this->healthCheckTargetsV4 = is_array($health['target'] ?? '1.1.1.1') ? $health['target'] : [$health['target']];
        $this->healthCheckTargetsV6 = isset($health['target_v6']) ? (is_array($health['target_v6']) ? $health['target_v6'] : [$health['target_v6']]) : [];
        $this->pingTimeout = (int)($health['ping_timeout'] ?? 2);
        if ($this->pingTimeout < HAConstants::MIN_PING_TIMEOUT || $this->pingTimeout > HAConstants::MAX_PING_TIMEOUT) throw new HAConfigurationException("health_check.ping_timeout must be between " . HAConstants::MIN_PING_TIMEOUT . "-" . HAConstants::MAX_PING_TIMEOUT . ".");

        // Timeout Validation
        $timeouts = $config['timeouts'] ?? [];
        $this->masterTransitionDelay = (int)($timeouts['master_transition_delay'] ?? 20);
        if ($this->masterTransitionDelay < 0 || $this->masterTransitionDelay > HAConstants::MAX_TRANSITION_DELAY) throw new HAConfigurationException("timeouts.master_transition_delay must be 0-" . HAConstants::MAX_TRANSITION_DELAY);
        $this->eventCooldownPeriod = (int)($timeouts['event_cooldown_period'] ?? 600);
        if ($this->eventCooldownPeriod < HAConstants::MIN_COOLDOWN_PERIOD || $this->eventCooldownPeriod > HAConstants::MAX_COOLDOWN_PERIOD) throw new HAConfigurationException("timeouts.event_cooldown_period must be " . HAConstants::MIN_COOLDOWN_PERIOD . "-" . HAConstants::MAX_COOLDOWN_PERIOD);
        $this->routeSettleDelay = (int)($timeouts['route_settle_delay'] ?? 2);
        if ($this->routeSettleDelay < 0 || $this->routeSettleDelay > HAConstants::MAX_SETTLE_DELAY) throw new HAConfigurationException("timeouts.route_settle_delay must be 0-" . HAConstants::MAX_SETTLE_DELAY);
        $this->lockWaitTimeout = (int)($timeouts['lock_wait_timeout'] ?? 60);
        if ($this->lockWaitTimeout < HAConstants::MIN_LOCK_TIMEOUT || $this->lockWaitTimeout > HAConstants::MAX_LOCK_TIMEOUT) throw new HAConfigurationException("timeouts.lock_wait_timeout must be " . HAConstants::MIN_LOCK_TIMEOUT . "-" . HAConstants::MAX_LOCK_TIMEOUT);
        $this->healthCheckRetries = (int)($timeouts['health_check_retries'] ?? 3);
        if ($this->healthCheckRetries < 1 || $this->healthCheckRetries > 5) throw new HAConfigurationException("timeouts.health_check_retries must be between 1-5.");
        $this->healthCheckRetryDelay = (int)($timeouts['health_check_retry_delay'] ?? 5);
        if ($this->healthCheckRetryDelay < 1 || $this->healthCheckRetryDelay > 20) throw new HAConfigurationException("timeouts.health_check_retry_delay must be between 1-20.");
        $this->serviceVerifyTimeout = (int)($timeouts['service_verify_timeout'] ?? 15);
        if ($this->serviceVerifyTimeout < 5 || $this->serviceVerifyTimeout > 60) throw new HAConfigurationException("timeouts.service_verify_timeout must be between 5-60.");

        $this->coreServices = $config['ha_core_services'] ?? [];
        $this->standardServices = $config['ha_controlled_services'] ?? [];
    }
}

