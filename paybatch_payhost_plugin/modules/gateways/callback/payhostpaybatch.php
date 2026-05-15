<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * This file handles the return POST from a Payfast Gateway with PayBatch transactionId
 *
 */

// Require libraries needed for gateway module functions
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once '../payhostpaybatch/lib/constants.php';

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'tbl');
}

// -----------------------------------------------------------------------
// Session-cookie guard — fixes "logged out after payment redirect" bug
//
// When PayGate sends the buyer back to this URL it does so via a
// cross-site POST (3-D Secure redirect pattern).  Modern browsers apply
// SameSite=Lax to session cookies, which means the cookie is NOT sent
// with cross-site POST requests.  Without an inbound session cookie PHP
// creates a brand-new anonymous session and queues a Set-Cookie response
// header.  When the browser then follows the Location from
// callback3DSecureRedirect it receives that new cookie and loses its
// original authenticated session — the user appears to be logged out.
//
// Fix: if no session cookie arrived with this request, strip the
// Set-Cookie header from our response so the browser keeps the real
// session cookie it already holds.
// -----------------------------------------------------------------------
if (!headers_sent() && !isset($_COOKIE[session_name()])) {
    header_remove('Set-Cookie');
    session_write_close();
}

/**
 * Check for existence of payhostpaybatch table and create if not
 */
if (!function_exists('createPayhostpaybatchTable')) {
    /**
     * @return bool
     */
    function createPayhostpaybatchTable(): bool
    {
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

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

createPayhostpaybatchTable();

/**
 * Idempotency lock table to prevent duplicate callbacks
 */
if (!function_exists('createPaygateCallbackLockTable')) {
    function createPaygateCallbackLockTable(): bool
    {
        try {
            $tableName = DB_PREFIX . 'paygate_callback_locks';

            if (!Capsule::schema()->hasTable($tableName)) {
                Capsule::schema()->create($tableName, function ($table) {
                    $table->increments('id');
                    $table->string('transaction_id', 50)->nullable();
                    $table->string('pay_request_id', 50)->nullable();
                    $table->integer('invoice_id')->unsigned()->nullable();
                    $table->string('status', 20)->default('processing');
                    $table->timestamps();

                    // Atomic idempotency keys
                    $table->unique('transaction_id');
                    $table->unique('pay_request_id');
                });
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

createPaygateCallbackLockTable();

/**
 * @param string $pgid
 * @param string $key
 * @param string $reqid
 *
 * @return array ['token' => $token, 'reference' => $reference, 'transactionId' => $transactionId]
 * @throws SoapFault
 *
 * Payfast Gateway Query Request to retrieve card token from authorised vault transaction
 */
function getQuery(string $pgid, string $key, string $reqid): array
{
    $soap       = <<<SOAP
            <ns1:SingleFollowUpRequest>
                <ns1:QueryRequest>
                    <ns1:Account>
                        <ns1:PayGateId>$pgid</ns1:PayGateId>
                        <ns1:Password>$key</ns1:Password>
                    </ns1:Account>
                    <ns1:PayRequestId>$reqid</ns1:PayRequestId>
                </ns1:QueryRequest>
            </ns1:SingleFollowUpRequest>
SOAP;
    $wsdl       = PAYHOSTAPIWSDL;
    $soapClient = new SoapClient($wsdl, ['trace' => 1]);
    try {
        $result = $soapClient->__soapCall(
            'SingleFollowUp',
            [
                new SoapVar($soap, XSD_ANYXML),
            ]
        );

        if ($result) {
            // Live PayGate servers return values with leading/trailing whitespace
            // and newlines inside XML elements — trim every field defensively.
            $vaultId       = isset($result->QueryResponse->Status->VaultId)
                ? trim((string)$result->QueryResponse->Status->VaultId) : null;
            $reference     = isset($result->QueryResponse->Status->Reference)
                ? trim((string)$result->QueryResponse->Status->Reference) : null;
            $transactionId = isset($result->QueryResponse->Status->TransactionId)
                ? trim((string)$result->QueryResponse->Status->TransactionId) : null;
            $data1         = isset($result->QueryResponse->Status->PayVaultData[0]->value)
                ? trim((string)$result->QueryResponse->Status->PayVaultData[0]->value) : null;
            $data2         = isset($result->QueryResponse->Status->PayVaultData[1]->value)
                ? trim((string)$result->QueryResponse->Status->PayVaultData[1]->value) : null;
            $userId        = isset($result->QueryResponse->UserDefinedFields->value)
                ? trim((string)$result->QueryResponse->UserDefinedFields->value) : null;
        } else {
            $vaultId = null;
        }
    } catch (SoapFault $fault) {
        $vaultId = null;
    }

    $token = $vaultId;

    return [
        'token'         => $token,
        'reference'     => $reference ?? null,
        'transactionId' => $transactionId ?? null,
        'vaultData1'    => $data1 ?? null,
        'vaultData2'    => $data2 ?? null,
        'userId'        => $userId ?? null,
    ];
}

// Get current user
$userId = intval($_SESSION['uid']);

// Detect module name from filename
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

// Check if we are in test mode
$testMode = $gatewayParams['testMode'];
if ($testMode == 'on') {
    $payHostId         = PAYGATETESTID;
    $payBatchId        = PAYGATETESTID;
    $payHostSecretKey  = PAYGATETESTKEY;
    $payBatchSecretKey = PAYGATETESTKEY;
} else {
    $payHostId         = $gatewayParams['payHostID'];
    $payBatchId        = $gatewayParams['payBatchID'];
    $payHostSecretKey  = $gatewayParams['payHostSecretKey'];
    $payBatchSecretKey = $gatewayParams['payBatchSecretKey'];
}

// Retrieve data returned in payment gateway callback
// We need to distinguish between a return from Payfast Gateway and a return from PayBatch

if (isset($_POST['PAY_REQUEST_ID']) && isset($_POST['TRANSACTION_STATUS'])) {
    // Payfast Gateway postback

    // Log POST fields but redact the CHECKSUM to avoid credential leakage in Activity Log
    $safePost = $_POST;
    if (isset($safePost['CHECKSUM'])) {
        $safePost['CHECKSUM'] = '***redacted***';
    }
    logActivity('Postback: ' . json_encode($safePost));
    logTransaction($gatewayModuleName, null, 'Postback: ' . json_encode($safePost));
    $payRequestId             = filter_var($_POST['PAY_REQUEST_ID']);
    $tblpayhostpaybatch       = DB_PREFIX . 'payhostpaybatch';
    $tblpayhostpaybatchvaults = DB_PREFIX . 'payhostpaybatchvaults';
    $reference                = Capsule::table($tblpayhostpaybatch)
                                       ->where('recordtype', 'transactionrecord')
                                       ->where('recordid', $payRequestId)
                                       ->value('recordval');

    logactivity('Reference: ' . $reference);
    logTransaction($gatewayModuleName, null, 'Reference: ' . $reference);

    $status   = htmlspecialchars($_POST['TRANSACTION_STATUS'], ENT_QUOTES, 'UTF-8');
    $verified = false;

    // Verify transaction key
    $checkString = $payHostId . $payRequestId . $status . $reference . $payHostSecretKey;
    $check       = md5($checkString);
    $verified    = hash_equals($check, $_POST['CHECKSUM']);
    if (!$verified) {
        // Validity not verified
        // Failed
        logActivity('Validity not verified: ' . $payRequestId . '_' . $reference);
        callback3DSecureRedirect($reference, false);
        exit;
    }

    // Make a request to get the Vault id
    if ($verified && $status == 1) {
        try {
            $response = getQuery($payHostId, $payHostSecretKey, $payRequestId);
        } catch (SoapFault $fault) {
            die ($fault->getMessage() . PHP_EOL);
        }

        $transactionId = $response['transactionId'];

        // ---- Idempotency lock: first callback wins ----
        if (empty($transactionId)) {
            logActivity("Missing transactionId from query: {$payRequestId}_{$reference}");
            logTransaction($gatewayModuleName, "Missing transactionId: {$payRequestId}_{$reference}", 'failed');
            callback3DSecureRedirect($reference, false);
            exit;
        }

        $lockTable = DB_PREFIX . 'paygate_callback_locks';

        try {
            Capsule::table($lockTable)->insert([
                                                   'transaction_id' => $transactionId,
                                                   'pay_request_id' => $payRequestId,
                                                   'invoice_id'     => (int)$reference,
                                                   'status'         => 'processing',
                                                   'created_at'     => date('Y-m-d H:i:s'),
                                                   'updated_at'     => date('Y-m-d H:i:s'),
                                               ]);

            logActivity("Lock acquired: {$payRequestId}_{$transactionId}_{$reference}");
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate key = already processed (or currently processing) this callback
            logActivity("Duplicate callback blocked by lock: {$payRequestId}_{$transactionId}_{$reference}");
            logTransaction(
                $gatewayModuleName,
                "Duplicate callback blocked by lock: {$payRequestId}_{$transactionId}_{$reference}",
                'duplicate'
            );
            callback3DSecureRedirect($reference, true);
            exit;
        }

        $card_number = (string)($response['vaultData1'] ?? '');
        $card_expiry = (string)($response['vaultData2'] ?? '');
        $userId      = $response['userId'];

        // Check for token and valid format
        $vaultPattern = '/^[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}$/';
        $token        = !empty($response['token']) ? (string)$response['token'] : '';
        if (preg_match($vaultPattern, $token) !== 1) {
            $token = null;
        }

        // Store the token if valid — wrapped in try/catch so a vault error never blocks invoice payment
        if ($token) {
            try {
                // Priority 1: exact match (same token + same user) — refresh card details only
                $byToken = Capsule::table($tblpayhostpaybatchvaults)
                                  ->where('token', $token)
                                  ->where('user_id', $userId)
                                  ->first();

                if ($byToken) {
                    Capsule::table($tblpayhostpaybatchvaults)
                           ->where('id', $byToken->id)
                           ->update(['card_number' => $card_number, 'card_expiry' => $card_expiry]);
                } else {
                    // Priority 2: same card already stored for this user under a different (stale) token — replace it
                    $byCard = Capsule::table($tblpayhostpaybatchvaults)
                                     ->where('user_id', $userId)
                                     ->where('card_number', $card_number)
                                     ->where('card_expiry', $card_expiry)
                                     ->first();

                    if ($byCard) {
                        // Mask tokens in logs — first 8 + last 4 chars of UUID
                        $maskFn    = static fn(string $t): string => strlen($t) > 12
                            ? substr($t, 0, 8) . '****' . substr($t, -4) : '****';
                        $oldMasked = $maskFn((string)$byCard->token);
                        $newMasked = $maskFn((string)$token);
                        logActivity(
                            "PayHost vault: Replacing stale token for user {$userId} card {$card_number} "
                            . "(old: {$oldMasked} → new: {$newMasked})"
                        );
                        Capsule::table($tblpayhostpaybatchvaults)
                               ->where('id', $byCard->id)
                               ->update(
                                   ['token' => $token, 'card_number' => $card_number, 'card_expiry' => $card_expiry]
                               );
                    } else {
                        // Priority 3: genuinely new card for this user
                        Capsule::table($tblpayhostpaybatchvaults)
                               ->insert([
                                            'user_id'     => $userId,
                                            'token'       => $token,
                                            'card_number' => $card_number,
                                            'card_expiry' => $card_expiry,
                                        ]);
                    }
                }
            } catch (Throwable $vaultEx) {
                // Vault storage failure must never prevent the invoice from being marked paid
                logActivity("PayHost vault storage error (non-fatal): " . $vaultEx->getMessage());
            }
        }

        // Get the current invoice and check its status
        $command = 'GetInvoice';
        $data    = [
            'invoiceid' => $reference,
        ];
        $invoice = localApi($command, $data);
        // Log only safe fields — exclude client PII from Activity Log
        $safeInvoice = [
            'result'    => $invoice['result'] ?? null,
            'invoiceid' => $invoice['invoiceid'] ?? null,
            'status'    => $invoice['status'] ?? null,
            'total'     => $invoice['total'] ?? null,
            'balance'   => $invoice['balance'] ?? null,
        ];
        logActivity("PayHost callback: GetInvoice result for {$reference}: " . json_encode($safeInvoice));

        // WHMCS built-in callback guards (these die() if validation fails — logged above for diagnosis)
        checkCbInvoiceID($reference, $gatewayModuleName);
        checkCbTransID($transactionId);

        // (Optional safety) If invoice already paid, do nothing
        if (!empty($invoice['status']) && strtolower($invoice['status']) === 'paid') {
            logActivity("Invoice already paid, skipping: {$reference} ({$transactionId})");
            Capsule::table($lockTable)
                   ->where('transaction_id', $transactionId)
                   ->update([
                                'status'     => 'completed',
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
            callback3DSecureRedirect($reference, true);
            exit;
        }

        // Add invoice payment
        $command = 'AddInvoicePayment';
        $data    = [
            'invoiceid' => $reference,
            'transid'   => $transactionId,
            'gateway'   => $gatewayModuleName,
        ];
        $result  = localAPI($command, $data);

        // Log only result/message — the full $result object may include card/client data
        $safeResult = ['result' => $result['result'] ?? null, 'message' => $result['message'] ?? null];
        logActivity(
            "PayHost callback: AddInvoicePayment result for invoice {$reference} txid {$transactionId}: " . json_encode(
                $safeResult
            )
        );

        // Mark lock completed
        Capsule::table($lockTable)
               ->where('transaction_id', $transactionId)
               ->update([
                            'status'     => 'completed',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);

        if (isset($result['result']) && $result['result'] === 'success') {
            logTransaction($gatewayModuleName, $response, 'success');
            logActivity('Payment successful: ' . $payRequestId . '_' . $reference);
            callback3DSecureRedirect($reference, true);
        } else {
            logTransaction($gatewayModuleName, $result, 'AddInvoicePayment failed');
            logActivity(
                'AddInvoicePayment failed for: ' . $payRequestId . '_' . $reference . ' — ' . json_encode($result)
            );
            callback3DSecureRedirect($reference, false);
        }
        exit;
    } else {
        // Failed
        logTransaction($gatewayModuleName, null, 'failed');
        logActivity('Payment failed: ' . $payRequestId . '_' . $reference);
        callback3DSecureRedirect($reference, false);
    }
}

// -----------------------------------------------------------------------
// Browser return path (?return=1)
//
// PayGate POSTs to $returnUrl (this file + ?return=1) when redirecting the
// buyer's browser back after payment.  We must NOT re-run AddInvoicePayment
// here — the server-to-server notify (no ?return=1) already did that.
// All we do is verify the CHECKSUM and issue the success / failure redirect
// so the buyer lands on the correct WHMCS invoice page.
// -----------------------------------------------------------------------
if (!empty($_GET['return']) && isset($_POST['PAY_REQUEST_ID']) && isset($_POST['TRANSACTION_STATUS'])) {
    $payRequestId = (string)filter_var($_POST['PAY_REQUEST_ID'], FILTER_SANITIZE_SPECIAL_CHARS);
    // Keep status as raw string for CHECKSUM calculation (mirrors PayGate server-side calculation)
    $statusRaw  = htmlspecialchars((string)$_POST['TRANSACTION_STATUS'], ENT_QUOTES, 'UTF-8');
    $statusCode = (int)$_POST['TRANSACTION_STATUS'];

    $tblpayhostpaybatch = DB_PREFIX . 'payhostpaybatch';
    $reference          = Capsule::table($tblpayhostpaybatch)
                                 ->where('recordtype', 'transactionrecord')
                                 ->where('recordid', $payRequestId)
                                 ->value('recordval');

    $checkString = $payHostId . $payRequestId . $statusRaw . $reference . $payHostSecretKey;
    $verified    = hash_equals(md5($checkString), (string)($_POST['CHECKSUM'] ?? ''));

    logActivity(
        'PayHost browser-return: payRequestId=' . $payRequestId
        . ' reference=' . $reference
        . ' status=' . $statusCode
        . ' verified=' . ($verified ? 'yes' : 'no')
    );

    if ($verified && $statusCode === 1) {
        callback3DSecureRedirect($reference, true);
    } else {
        callback3DSecureRedirect($reference, false);
    }
    exit;
}
