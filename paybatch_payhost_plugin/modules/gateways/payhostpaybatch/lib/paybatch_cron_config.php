<?php

declare(strict_types=1);

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * Configuration loader for PayBatch cron jobs - uses WHMCS gateway configuration
 */

require_once __DIR__ . '/gateway_helper.php';

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'tbl');
}

// Get credentials from gateway configuration
$whmcsApiCredentials = getWHMCSApiCredentials();
$payBatchCredentials = getPayBatchCredentials();

// Set global variables for backwards compatibility
$GLOBALS['api_identifier'] = $whmcsApiCredentials['api_identifier'];
$GLOBALS['api_secret']     = $whmcsApiCredentials['api_secret'];
$GLOBALS['api_access_key'] = $whmcsApiCredentials['api_access_key'];
$GLOBALS['api_url']        = $whmcsApiCredentials['api_url'];

$GLOBALS['payBatchId']        = $payBatchCredentials['id'];
$GLOBALS['payBatchSecretKey'] = $payBatchCredentials['secret_key'];
$GLOBALS['payBatchApiWsdl']   = 'https://secure.paygate.co.za/paybatch/1.2/PayBatch.wsdl';

// CLI security check
if (php_sapi_name() !== 'cli') {
    // Safely abort only if someone tries to run this file directly via web.
    if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
        die('This script must be run from the command line.' . PHP_EOL);
    }
}
