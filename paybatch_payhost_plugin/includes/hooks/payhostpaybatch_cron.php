<?php

use WHMCS\Database\Capsule;


/**
 * PAY HOOK (Triggers PayBatch upload)
 */

add_hook('DailyCronJob', 100, function () {
    logActivity('PayBatchCronPay: Triggered.');
    try {
        $rootDir = dirname(__DIR__, 2);

        if (!function_exists('getGatewayVariables')) {
            require_once $rootDir . '/includes/gatewayfunctions.php';
        }

        $moduleDir         = $rootDir . '/modules/gateways/payhostpaybatch';
        $gatewayModuleName = 'payhostpaybatch';

        $gatewayParams = getGatewayVariables($gatewayModuleName);

        if (empty($gatewayParams['type'])) {
            logActivity("PayHostPayBatch not active — skipping cron hook");

            return;
        }

        require_once $moduleDir . '/lib/paybatch_cron_config.php';
        require_once $moduleDir . '/lib/paybatch_cron_common.php';
        require_once $moduleDir . '/classes/cron/paybatchSoapCron.class.php';

        $payBatchSoap = new PaybatchSoapCron();

        $invoices = getInvoices1();

        if (!$invoices || count($invoices) === 0) {
            logActivity('PayBatchCronPay: No invoices found.');

            return;
        }

        $today = new DateTime();
        $data  = [];

        foreach ($invoices as $invoice) {
            $firstname = $invoice['firstname'] ?? '';
            $lastname  = $invoice['lastname'] ?? '';
            $duedate   = $invoice['duedate'] ?? '';
            $status    = $invoice['status'] ?? '';
            $total     = $invoice['total'] ?? 0;
            $invoiceId = $invoice['id'] ?? null;

            // Skip if invoice already recorded as "sent" for a pending upload
            $alreadySent = Capsule::table(DB_PREFIX . 'payhostpaybatch')
                                  ->where('recordtype', 'sent_invoice')
                                  ->where('recordid', $invoiceId)
                                  ->exists();

            if ($alreadySent) {
                logActivity("PayBatchCronPay: Invoice {$invoiceId} already sent in a pending batch — skipping.");
                continue;
            }

            if (new DateTime($duedate) <= $today && $status === 'Unpaid' && !empty($invoice['cardnum'])) {
                $item   = [];
                $item[] = 'A';
                $item[] = $invoiceId;
                $item[] = $firstname . '_' . $lastname;
                $item[] = $invoice['cardnum'];
                $item[] = '00';
                $item[] = intval($total * 100);

                $data[] = $item;
            }
        }

        if (count($data) === 0) {
            logActivity('PayBatchCronPay: No eligible invoices for PayBatch.');

            return;
        }

        logActivity('PayBatchCronPay: Prepared Batch → ' . json_encode($data));

        $errors   = false;
        $invalids = true;

        while (!$errors && $invalids && count($data) > 0) {
            try {
                $payBatchId        = $gatewayParams['payBatchID'] ?? null;
                $payBatchSecretKey = $gatewayParams['payBatchSecretKey'] ?? null;
                $soap              = $payBatchSoap->getAuthRequest($data);
                $wsdl              = PAYBATCHAPIWSDL;
                $options           = ['trace' => 1, 'login' => $payBatchId, 'password' => $payBatchSecretKey];

                $client = new SoapClient($wsdl, $options);
                $result = $client->__soapCall('Auth', [new SoapVar($soap, XSD_ANYXML)]);

                if ($result->Invalid == 0) {
                    $invalids = false;
                    $uploadId = $result->UploadID;

                    // Confirm it
                    $confirmXml = $payBatchSoap->getConfirmRequest($uploadId);
                    $confirm    = $client->__soapCall('Confirm', [new SoapVar($confirmXml, XSD_ANYXML)]);

                    if ($confirm->Invalid != 0) {
                        $errors = true;
                    }
                } else {
                    foreach ($result->InvalidReason as $invalid) {
                        unset($data[$invalid->Line - 1]);
                    }
                }
            } catch (SoapFault $fault) {
                logActivity('PayBatchCronPay SOAP Fault: ' . $fault->getMessage());
                $errors = true;
            }
        }

        if ($errors) {
            logActivity('PayBatchCronPay: Failed to process.');

            return;
        }

        // store UploadID
        Capsule::table(DB_PREFIX . 'payhostpaybatch')->insert([
                                                                  'recordtype' => 'uploadid',
                                                                  'recordid'   => $uploadId,
                                                                  'recordval'  => 'true'
                                                              ]);

        // Record each invoice as "sent" so we can exclude them from future Pay uploads until Query clears them.
        foreach ($data as $item) {
            // item[1] is invoiceId as per your existing structure
            $invoiceIdForSent = $item[1] ?? null;
            if ($invoiceIdForSent) {
                Capsule::table(DB_PREFIX . 'payhostpaybatch')->insert([
                                                                          'recordtype' => 'sent_invoice',
                                                                          'recordid'   => $invoiceIdForSent,
                                                                          'recordval'  => $uploadId
                                                                      ]);
                logActivity("PayBatchCronPay: Recorded invoice {$invoiceIdForSent} as sent (UploadID {$uploadId}).");
            }
        }

        logActivity(
            count($data) . ' invoices were successfully uploaded to Payfast Gateway with PayBatch for processing'
        );
    } catch (Throwable $e) {
        logActivity("PayHostPayBatch CRON EXCEPTION: " . $e->getMessage());
        error_log("PayHostPayBatch CRON EXCEPTION: " . $e->getMessage());
    }
});

function getInvoices1(): array
{
    try {
        $invoices = Capsule::table('tblinvoices as i')
                           ->join('tblclients as t', 'i.userid', '=', 't.id')
                           ->join('tblpayhostpaybatchvaults as pay', 'pay.user_id', '=', 'i.userid')
                           ->select([
                                        'i.*',
                                        't.firstname',
                                        't.lastname',
                                        'pay.token as cardnum'
                                    ])
                           ->where('i.status', 'Unpaid')
                           ->where('i.paymentmethod', GATEWAY)
                           ->limit(1)
                           ->get()
                           ->toArray();

        return array_map(fn($row) => (array)$row, $invoices);
    } catch (Exception $e) {
        logActivity('PayBatch Cron - Error fetching invoices: ' . $e->getMessage());

        return [];
    }
}


/**
 * QUERY HOOK (Triggers PayBatch status checking)
 */

add_hook('AfterCronJob', 99, function () {
    logActivity('PayBatchCronQuery: Triggered.');
    $rootDir = dirname(__DIR__, 2);

    $lastRunFile = $rootDir . '/storage/logs/paybatch_last_hourly_run';

    // Only run once per hour
    if (file_exists($lastRunFile) && (time() - filemtime($lastRunFile)) < 3600) {
        logActivity("Testing PayBatchCronQuery: Already ran this hour; skipping.");

        return; // Already ran this hour
    }
    // Mark that we ran this hour
    touch($lastRunFile);

    if (!function_exists('getGatewayVariables')) {
        require_once $rootDir . '/includes/gatewayfunctions.php';
    }

    $moduleDir         = $rootDir . '/modules/gateways/payhostpaybatch';
    $gatewayModuleName = 'payhostpaybatch';

    $gatewayParams = getGatewayVariables($gatewayModuleName);
    require_once $moduleDir . '/lib/paybatch_cron_config.php';
    require_once $moduleDir . '/lib/paybatch_cron_common.php';
    require_once $moduleDir . '/classes/cron/paybatchSoapCron.class.php';

    global $payBatchSoap;

    if (!$payBatchSoap) {
        $payBatchSoap = new PaybatchSoapCron();
    }

    try {
        $payBatchId        = $gatewayParams['payBatchID'] ?? null;
        $payBatchSecretKey = $gatewayParams['payBatchSecretKey'] ?? null;
        // Use WHMCS Capsule instead of direct mysqli
        $batches = Capsule::table(DB_PREFIX . 'payhostpaybatch')
                          ->where('recordtype', 'uploadid')
                          ->pluck('recordid')
                          ->toArray();

        if (($nbatches = count($batches)) > 0) {
            logActivity('PayBatchCronQuery - Query batches: ' . json_encode($batches));

            foreach ($batches as $key => $batch) {
                $queryResult = doPayBatchQuery($payBatchSoap, $batch, $payBatchId, $payBatchSecretKey);
                logActivity('PayBatchCronQuery - Query result: ' . json_encode($queryResult));

                if (intval($queryResult->Unprocessed) == 0) {
                    logActivity('PayBatchCronQuery - Unprocessed: ' . $queryResult->Unprocessed);

                    if (!empty($queryResult->TransResult) && $queryResult->Success === 1) {
                        if (!is_array($queryResult->TransResult)) {
                            handleLineItem($queryResult->TransResult);
                        } else {
                            foreach ($queryResult->TransResult as $transResult) {
                                handleLineItem($transResult);
                            }
                        }

                        unset($batches[$key]);

                        // Delete uploadid
                        Capsule::table(DB_PREFIX . 'payhostpaybatch')
                               ->where('recordtype', 'uploadid')
                               ->where('recordid', $batch)
                               ->delete();

                        // Delete all sent_invoice rows
                        Capsule::table(DB_PREFIX . 'payhostpaybatch')
                               ->where('recordtype', 'sent_invoice')
                               ->where('recordval', $batch)
                               ->delete();

                        logActivity(
                            "PayBatchCronQuery: Cleared uploadid {$batch} and associated sent_invoice records."
                        );
                    }
                }
            }
            logActivity(
                'PayBatchCronQuery: ' . $nbatches . ' Payfast Gateway with PayBatch batches were queried for payment information and processed'
            );
            logActivity('PayBatchCronQuery: Completed.');
        } else {
            logActivity('PayBatchCronQuery: No Payfast Gateway with PayBatch batches were found for processing');
        }
    } catch (Exception $exception) {
        logActivity($exception->getMessage());
    }
});

/**
 * @param $transResult
 *
 * @return void
 */
function handleLineItem($transResult): void
{
    $transResult = explode(',', $transResult);
    $headings    = [
        'txId',
        'txType',
        'txRef',
        'authcode',
        'txStatusCode',
        'txStatusDescription',
        'txResultCode',
        'txResultDescription',
    ];
    $transResult = array_combine($headings, $transResult);
    logActivity('PayBatchCronQuery - Trans Result: ' . json_encode($transResult));
    echo 'PayBatchCronQuery - Trans Result: ' . json_encode($transResult);

    $dataApi  = [
        'invoiceid'     => $transResult['txRef'] ?? '',
        'paymentmethod' => 'payhostpaybatch',
        'status'        => 'Paid',
        'txId'          => $transResult['txId'] ?? '',
    ];
    $response = updateInvoicesApi($dataApi);
    logActivity('PayBatchCronQuery - Response: ' . json_encode($response));
}

/**
 * @param string $uploadId
 *
 * @return mixed|string
 */
function doPayBatchQuery(
    PaybatchSoapCron $payBatchSoap,
    string $uploadId,
    string $payBatchId,
    string $payBatchSecretKey
): mixed {
    $queryXml = $payBatchSoap->getQueryRequest($uploadId);
    $wsdl     = PAYBATCHAPIWSDL;
    $options  = ['trace' => 1, 'login' => $payBatchId, 'password' => $payBatchSecretKey];

    try {
        $soapClient = new SoapClient($wsdl, $options);

        $xml = $soapClient->__soapCall('Query', [
            new SoapVar($queryXml, XSD_ANYXML),
        ]);
    } catch (SoapFault $fault) {
        return $fault->getMessage() . PHP_EOL;
    }

    return $xml;
}

/**
 * @param array $data
 *
 * @return string|bool
 */
function updateInvoicesApi(array $data): string|bool
{
    return addInvoicePaymentApi($data);
}

/**
 * @param array $data
 *
 * @return bool|string
 */
function markInvoicesPaid(array $data): bool|string
{
    global $api_identifier, $api_secret, $api_url, $api_access_key;
    $postFields = [
        'username'     => $api_identifier,
        'password'     => $api_secret,
        'action'       => 'UpdateInvoice',
        'status'       => 'Paid',
        'responsetype' => 'json',
        'accesskey'    => $api_access_key,
        'invoiceid'    => $data['invoiceid'],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    $response = curl_exec($ch);
    $error    = curl_error($ch);

    if ($error == '') {
        return $response;
    } else {
        return $error;
    }
}

/**
 * @param array $data
 *
 * @return bool|string
 */
function addInvoicePaymentApi(array $data): bool|string
{
    global $api_identifier, $api_secret, $api_url, $api_access_key;

    // Check for duplicate transactions in invoice
    $postFields = [
        'username'     => $api_identifier,
        'password'     => $api_secret,
        'action'       => 'GetTransactions',
        'gateway'      => $data['paymentmethod'] ?? '',
        'responsetype' => 'json',
        'accesskey'    => $api_access_key,
        'invoiceid'    => $data['invoiceid'] ?? '',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    $response = curl_exec($ch);
    curl_close($ch);

    $transactions = json_decode($response)->transactions->transaction ?? [];

    $date = date('Y-m-d H:i:s');

    if (!empty($transactions)) {
        $date = $transactions[0]->date;
        logActivity("Transaction Date: " . $date);
    }

    // Check for duplicate TXID (always protect)
    foreach ($transactions as $transaction) {
        if (($data['txId'] ?? '') == ($transaction->transid ?? '')) {
            logActivity(
                "PayBatchCronQuery: Duplicate TXID {$data['txId']} for invoice {$data['invoiceid']}; skipping."
            );

            return false;
        }
    }

    $postFields = [
        'action'       => 'AddInvoicePayment',
        'username'     => $api_identifier,
        'password'     => $api_secret,
        'invoiceid'    => $data['invoiceid'] ?? '',
        'transid'      => $data['txId'] ?? '',
        'gateway'      => $data['paymentmethod'] ?? '',
        'date'         => $date,
        'responsetype' => 'json',
    ];
    $ch         = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);
    if ($error == '') {
        return $response;
    } else {
        return $error;
    }
}
 