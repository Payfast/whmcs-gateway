<?php

declare(strict_types=1);

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * Constants for PayGate / PayBatch module
 */

/**
 * Define constants with default values
 */
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
    define('PAYGATETESTKEY', 'test');
}
if (!defined('GATEWAY')) {
    define('GATEWAY', 'payhostpaybatch');
}

/**
 * Dynamically set document root for WHMCS context
 */
if (!defined('DOC_ROOT')) {
    $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
    $host   = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $port   = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] !== '80' && $_SERVER['SERVER_PORT'] !== '443'
        ? ':' . $_SERVER['SERVER_PORT'] : '';
    define('DOC_ROOT', $scheme . '://' . $host . $port . '/');
}
