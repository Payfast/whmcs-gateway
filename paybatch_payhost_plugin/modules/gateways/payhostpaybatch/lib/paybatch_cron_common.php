<?php

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * Common functions and setup for PayBatch cron jobs
 */
declare(strict_types=1);

use WHMCS\Database\Capsule;

require_once __DIR__ . '/gateway_helper.php';

// Define constants with default values
if (!defined('PAYHOSTAPI')) {
    define('PAYHOSTAPI', 'https://secure.paygate.co.za/payhost/process.trans');
}
if (!defined('PAYHOSTAPIWSDL')) {
    define('PAYHOSTAPIWSDL', 'https://secure.paygate.co.za/payhost/process.trans?wsdl');
}
if (!defined('PAYBATCHAPI')) {
    define('PAYBATCHAPI', 'https://secure.paygate.co.za/paybatch/1.2/process.trans');
}
if (!defined('PAYBATCHAPIWSDL')) {
    define('PAYBATCHAPIWSDL', 'https://secure.paygate.co.za/paybatch/1.2/PayBatch.wsdl');
}
if (!defined('PAYGATETESTID')) {
    define('PAYGATETESTID', '10011072130');
}
if (!defined('PAYGATETESTKEY')) {
    define('PAYGATETESTKEY', 'secret');
}
if (!defined('GATEWAY')) {
    define('GATEWAY', 'payhostpaybatch');
}

$docroot = '';
if (isset($_SERVER['REQUEST_SCHEME'])) {
    $docroot = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['SERVER_NAME'];
    if (isset($_SERVER['SERVER_PORT'])) {
        $docroot .= ':' . $_SERVER['SERVER_PORT'];
    }
}
$docroot .= '/';

// Get Gateway parameters using our helper function
try {
    $params = getPayhostpaybatchGatewayConfig();
    if (empty($params) || !isset($params['type'])) {
        throw new Exception("Gateway not configured or not active");
    }
} catch (Exception $exception) {
    die('Could not retrieve gateway parameters: ' . $exception->getMessage());
}

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'tbl');
}

// Create a table to store PayBatch transaction data using Capsule Schema
try {
    if (!Capsule::schema()->hasTable(DB_PREFIX . 'payhostpaybatch')) {
        Capsule::schema()->create(DB_PREFIX . 'payhostpaybatch', function ($table) {
            $table->increments('id');
            $table->string('recordtype', 20);
            $table->string('recordid', 50);
            $table->string('recordval', 50);
            $table->string('dbid', 10)->default('1');
            $table->timestamps();
        });
    }
} catch (Exception $e) {
    die('Database table creation failed: ' . $e->getMessage());
}

// Get system currencies using Capsule
try {
    $currencyData = Capsule::table('tblcurrencies')->get();
    $currencies   = [];
    foreach ($currencyData as $item) {
        $currencies[$item->id] = ['code' => $item->code, 'rate' => $item->rate];
    }
} catch (Exception $e) {
    die('Failed to retrieve currencies: ' . $e->getMessage());
}

// Check if test mode or not
$testMode          = $params['testMode'] ?? '';
$payHostId         = ($testMode == 'on') ? PAYGATETESTID : ($params['payHostID'] ?? '');
$payBatchId        = ($testMode == 'on') ? PAYGATETESTID : ($params['payBatchID'] ?? '');
$payHostSecretKey  = ($testMode == 'on') ? PAYGATETESTKEY : ($params['payHostSecretKey'] ?? '');
$payBatchSecretKey = ($testMode == 'on') ? PAYGATETESTKEY : ($params['payBatchSecretKey'] ?? '');

/**
 * API call
 *
 * @param array $options
 *
 * @return array
 */
function callApi(array $options): array
{
    global $api_identifier;
    global $api_secret;
    global $access_key;
    $whmcsUrl = DOC_ROOT . 'includes/api.php';

    $postfields = [
        'username'     => $api_identifier,
        'password'     => $api_secret,
        'responsetype' => 'json',
    ];

    $postfields = array_merge($postfields, $options);

    echo 'In callApi: ' . json_encode($postfields);

    // Call the API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $whmcsUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if (strlen($error) > 0) {
        return ['error' => true, 'response' => $error];
    }

    return ['error' => false, 'response' => json_decode($response, true)];
}
