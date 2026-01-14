<?php

declare(strict_types=1);

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * Helper functions for accessing gateway configuration securely
 */

use WHMCS\Database\Capsule;

/**
 * Get PayhostPaybatch gateway configuration from WHMCS database
 * This replaces the need for .env files and provides secure access to credentials
 *
 * @return array Gateway configuration parameters
 */
function getPayhostpaybatchGatewayConfig(): array
{
    static $cachedConfig = null;

    if ($cachedConfig !== null) {
        return $cachedConfig;
    }

    try {
        if (!function_exists('getGatewayVariables')) {
            // Find WHMCS root
            $cwd       = __DIR__;
            $maxDepth  = 6;
            $whmcsRoot = null;

            for ($i = 0; $i < $maxDepth; $i++) {
                if (file_exists($cwd . '/init.php')) {
                    $whmcsRoot = $cwd;
                    break;
                }
                $cwd = dirname($cwd);
            }

            if (!$whmcsRoot) {
                throw new Exception("WHMCS root not found");
            }

            require_once $whmcsRoot . '/init.php';
            require_once $whmcsRoot . '/includes/gatewayfunctions.php';
        }

        $cachedConfig = getGatewayVariables('payhostpaybatch');

        return $cachedConfig;
    } catch (Exception $e) {
        error_log("Gateway Helper ERROR: Could not retrieve gateway configuration - " . $e->getMessage());

        return [];
    }
}

/**
 * Get WHMCS API credentials from gateway configuration
 *
 * @return array API credentials
 */
function getWHMCSApiCredentials(): array
{
    $config = getPayhostpaybatchGatewayConfig();

    return [
        'api_identifier' => $config['whmcs_api_identifier'] ?? '',
        'api_secret'     => $config['whmcs_api_secret'] ?? '',
        'api_access_key' => $config['whmcs_api_access_key'] ?? '',
        'api_url'        => $config['whmcs_api_url'] ?? ''
    ];
}

/**
 * Get PayBatch credentials from gateway configuration
 *
 * @return array PayBatch credentials
 */
function getPayBatchCredentials(): array
{
    $config = getPayhostpaybatchGatewayConfig();

    return [
        'id'         => $config['payBatchID'] ?? '',
        'secret_key' => $config['payBatchSecretKey'] ?? ''
    ];
}

/**
 * Get PayHost credentials from gateway configuration
 *
 * @return array PayHost credentials
 */
function getPayHostCredentials(): array
{
    $config = getPayhostpaybatchGatewayConfig();

    return [
        'id'         => $config['payHostID'] ?? '',
        'secret_key' => $config['payHostSecretKey'] ?? ''
    ];
}

/**
 * Check if gateway is in test mode
 *
 * @return bool
 */
function isTestMode(): bool
{
    $config = getPayhostpaybatchGatewayConfig();

    return ($config['testMode'] ?? '') === 'on';
}
