<?php
/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
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
            $vaultId       = $result->QueryResponse->Status->VaultId ?? null;
            $reference     = $result->QueryResponse->Status->Reference ?? null;
            $transactionId = $result->QueryResponse->Status->TransactionId ?? null;
            $data1         = $result->QueryResponse->Status->PayVaultData[0]->value ?? null;
            $data2         = $result->QueryResponse->Status->PayVaultData[1]->value ?? null;
            $userId        = $result->QueryResponse->UserDefinedFields->value ?? null;
        } else {
            $vaultId = null;
        }
    } catch (SoapFault $fault) {
        $vaultId = null;
    }


    $token = $vaultId;


    return [
        'token'         => $token,
        'reference'     => $reference,
        'transactionId' => $transactionId,
        'vaultData1'    => $data1,
        'vaultData2'    => $data2,
        'userId'        => $userId,
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

    logActivity('Postback: ' . json_encode($_POST));
    logTransaction($gatewayModuleName, null, 'Postback: ' . json_encode($_POST));
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
    }

    // Make a request to get the Vault id
    if ($verified && $status == 1) {
        try {
            $response = getQuery($payHostId, $payHostSecretKey, $payRequestId);
        } catch (SoapFault $fault) {
            die ($fault->getMessage() . PHP_EOL);
        }

        $transactionId = $response['transactionId'];
        $card_number   = $response['vaultData1'];
        $card_expiry   = $response['vaultData2'];
        $userId        = $response['userId'];

        // Check for token and valid format
        $vaultPattern = '/^[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}$/';
        $token        = !empty($response['token']) ? $response['token'] : null;
        if (preg_match($vaultPattern, $token) != 1) {
            $token = null;
        }

        // Store the token if valid
        if ($token) {
            $clientExists = Capsule::table($tblpayhostpaybatchvaults)
                                   ->where('token', $token)
                                   ->where('user_id', $userId)
                                   ->value('token');

            if (strlen($clientExists) > 0) {
                Capsule::table($tblpayhostpaybatchvaults)
                       ->where('token', $token)
                       ->where('user_id', $userId)
                       ->update(['card_number' => $card_number, 'card_expiry' => $card_expiry]);
            } else {
                Capsule::table($tblpayhostpaybatchvaults)
                       ->insert(
                           [
                               'user_id'     => $userId,
                               'token'       => $token,
                               'card_number' => $card_number,
                               'card_expiry' => $card_expiry,
                           ]
                       );
            }
        }

        // Get the current invoice and check its status
        $command = 'GetInvoice';
        $data    = [
            'invoiceid' => $reference,
        ];
        $invoice = localApi($command, $data);

        // Get transactions for invoice
        $command      = 'GetTransactions';
        $data         = [
            'invoiceid' => $reference,
        ];
        $transactions = localAPI($command, $data);

        // Check for duplicate transaction
        $duplicate = false;
        if (isset($transactions['transactions']['transaction'])) {
            $transactionList = $transactions['transactions']['transaction'];
            // Handle both single transaction (array) and multiple transactions (array of arrays)
            if (isset($transactionList['transid'])) {
                // Single transaction
                $transactionList = [$transactionList];
            }
            foreach ($transactionList as $transaction) {
                if ($transactionId == $transaction['transid']) {
                    $duplicate = true;
                    break;
                }
            }
        }
        if (!$duplicate) {
            // Add invoice payment
            $command = 'AddInvoicePayment';
            $data    = [
                'invoiceid' => $reference,
                'transid'   => $transactionId,
                'gateway'   => $gatewayModuleName,
            ];
            $result  = localAPI($command, $data);
            logTransaction($gatewayModuleName, $response, 'success');
            logActivity('Payment successful: ' . $payRequestId . '_' . $reference);
            callback3DSecureRedirect($reference, true);
        } else {
            logActivity('Duplicate transaction: ' . $payRequestId . '_' . $transactionId . '_' . $reference);
            logTransaction(
                $gatewayModuleName,
                'Duplicate transaction: ' . $payRequestId . '_' . $transactionId . '_' . $reference,
                'duplicate'
            );
            callback3DSecureRedirect($reference, false);
        }
    } else {
        // Failed
        logTransaction($gatewayModuleName, null, 'failed');
        logActivity('Payment failed: ' . $payRequestId . '_' . $reference);
        callback3DSecureRedirect($reference, false);
    }
}
