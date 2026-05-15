<?php

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'tbl');
}

if (!defined('PAYBATCH_QUERY_MAX_FAILURES')) {
    define('PAYBATCH_QUERY_MAX_FAILURES', 5);
}

// ---------------------------------------------------------------------------
// Idempotent index migrations — run once on first load; no-op thereafter.
//
// 1. idx_invoices_status_method_user: covers the getInvoices1() subquery
//    WHERE status=? AND paymentmethod=? GROUP BY userid — eliminates the
//    "Using temporary; Using filesort" in the EXPLAIN derived table.
//
// 2. idx_vault_user_id: dedicated single-column index on user_id for the
//    vault JOIN — converts the BNL full-table scan to a ref lookup.
// ---------------------------------------------------------------------------
try {
    $pdo = Capsule::connection()->getPdo();

    // Index 1: tblinvoices (status, paymentmethod, userid)
    $idxCheck = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'tblinvoices'
           AND INDEX_NAME   = 'idx_invoices_status_method_user'"
    )->fetchColumn();

    if ((int)$idxCheck === 0) {
        // Use prefix lengths to stay within the 767-byte InnoDB key limit when
        // the server has innodb_large_prefix=OFF or ROW_FORMAT=COMPACT (MySQL 5.6
        // defaults).  status(20) + paymentmethod(50) + userid(4) = 284 bytes —
        // well under the limit and still covers all real values.
        $pdo->exec(
            'ALTER TABLE `tblinvoices`
             ADD INDEX `idx_invoices_status_method_user` (`status`(20), `paymentmethod`(50), `userid`)'
        );
        logActivity('PayBatch: Added index idx_invoices_status_method_user on tblinvoices.');
    }

    // Index 2: tblpayhostpaybatchvaults (user_id)
    $idxCheck2 = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'tblpayhostpaybatchvaults'
           AND INDEX_NAME   = 'idx_vault_user_id'"
    )->fetchColumn();

    if ((int)$idxCheck2 === 0) {
        $pdo->exec(
            'ALTER TABLE `tblpayhostpaybatchvaults`
             ADD INDEX `idx_vault_user_id` (`user_id`)'
        );
        logActivity('PayBatch: Added index idx_vault_user_id on tblpayhostpaybatchvaults.');
    }
} catch (Throwable $idxEx) {
    logActivity('PayBatch: Index migration failed (non-fatal): ' . $idxEx->getMessage());
}

/**
 * PAY HOOK (Triggers PayBatch upload)
 */
add_hook('DailyCronJob', 100, function () {
    logActivity('PayBatchCronPay: Triggered.');

    // ---- Cleanup old PayGate callback locks (keeps table small) ----
    try {
        $lockTable  = DB_PREFIX . 'paygate_callback_locks';
        $daysToKeep = 90;

        if (Capsule::schema()->hasTable($lockTable)) {
            $deleted = Capsule::table($lockTable)
                              ->where('status', 'completed')
                              ->where('created_at', '<', Capsule::raw("DATE_SUB(NOW(), INTERVAL {$daysToKeep} DAY)"))
                              ->delete();

            if ($deleted > 0) {
                logActivity(
                    "PayGate Callback Lock Cleanup: {$deleted} old completed locks removed (>{$daysToKeep} days)"
                );
            }
        }
    } catch (Throwable $e) {
        logActivity('PayGate Callback Lock Cleanup failed: ' . $e->getMessage());
    }

    try {
        $rootDir = dirname(__DIR__, 2);

        if (!function_exists('getGatewayVariables')) {
            require_once $rootDir . '/includes/gatewayfunctions.php';
        }

        $moduleDir         = $rootDir . '/modules/gateways/payhostpaybatch';
        $gatewayModuleName = 'payhostpaybatch';

        $gatewayParams = getGatewayVariables($gatewayModuleName);

        if (empty($gatewayParams['type'])) {
            logActivity('PayHostPayBatch not active — skipping cron hook');

            return;
        }

        require_once $moduleDir . '/lib/paybatch_cron_config.php';
        require_once $moduleDir . '/lib/paybatch_cron_common.php';
        require_once $moduleDir . '/classes/cron/paybatchSoapCron.class.php';

        $payBatchSoap = new PaybatchSoapCron();

        $invoices = getInvoices1();

        if (empty($invoices)) {
            logActivity('PayBatchCronPay: No invoices found.');

            return;
        }

        $today          = new DateTime();
        $data           = [];
        $seenInvoiceIds = [];

        foreach ($invoices as $invoice) {
            $firstname = $invoice['firstname'] ?? '';
            $lastname  = $invoice['lastname'] ?? '';
            $duedate   = $invoice['duedate'] ?? '';
            $status    = $invoice['status'] ?? '';
            $invoiceId = isset($invoice['id']) ? (int)$invoice['id'] : 0;

            if ($invoiceId <= 0) {
                continue;
            }

            if (isset($seenInvoiceIds[$invoiceId])) {
                logActivity(
                    "PayBatchCronPay: Invoice {$invoiceId} already added in this run — skipping duplicate row."
                );
                continue;
            }

            $seenInvoiceIds[$invoiceId] = true;

            $alreadySent = false;
            try {
                $alreadySent = Capsule::table(DB_PREFIX . 'payhostpaybatch')
                                      ->where('recordtype', 'sent_invoice')
                                      ->where('recordid', $invoiceId)
                                      ->exists();
            } catch (Throwable $e) {
                logActivity(
                    "PayBatchCronPay: Failed checking sent_invoice state for invoice {$invoiceId}: " . $e->getMessage()
                );
                continue;
            }

            if ($alreadySent) {
                logActivity("PayBatchCronPay: Invoice {$invoiceId} already sent in a pending batch — skipping.");
                continue;
            }

            if (isInvoiceBlockedForCycle($invoiceId)) {
                logActivity("PayBatchCronPay: Invoice {$invoiceId} blocked for this billing cycle — skipping.");
                continue;
            }

            if (empty($invoice['cardnum'])) {
                continue;
            }

            // Resolve the exact balance due using the WHMCS Invoice model.
            // Invoice::balance = subtotal of all line items minus any payments already applied.
            // This handles partial payments and multiple invoice items correctly.
            $total = 0.0;
            try {
                $invoiceModel = \WHMCS\Billing\Invoice::find($invoiceId);
                if ($invoiceModel === null) {
                    logActivity("PayBatchCronPay: Invoice {$invoiceId} not found via Invoice model — skipping.");
                    continue;
                }
                $total = (float)$invoiceModel->balance;
            } catch (Throwable $e) {
                logActivity("PayBatchCronPay: Failed to resolve balance for invoice {$invoiceId}: " . $e->getMessage());
                continue;
            }

            if ($total <= 0) {
                logActivity("PayBatchCronPay: Invoice {$invoiceId} balance is {$total} — nothing to charge, skipping.");
                continue;
            }

            try {
                $invoiceDueDate = new DateTime($duedate);
            } catch (Throwable $e) {
                logActivity("PayBatchCronPay: Invalid due date for invoice {$invoiceId}: {$duedate}");
                continue;
            }

            if ($invoiceDueDate <= $today && $status === 'Unpaid') {
                // Mask token: show first 4 and last 4 chars only (e.g. 4e5e****90f6)
                $rawToken    = (string)$invoice['cardnum'];
                $maskedToken = strlen($rawToken) > 8
                    ? substr($rawToken, 0, 4) . '****' . substr($rawToken, -4)
                    : '********';

                $amountCents = (int)round($total * 100);

                logActivity(
                    "PayBatchCronPay: Adding invoice {$invoiceId} to batch"
                    . " | token={$maskedToken}"
                    . " | balance={$total}"
                    . " | amountCents={$amountCents}"
                );

                $data[] = [
                    'A',
                    $invoiceId,
                    $firstname . '_' . $lastname,
                    $invoice['cardnum'],
                    '00',
                    $amountCents,
                ];
            }
        }

        if (count($data) === 0) {
            logActivity('PayBatchCronPay: No eligible invoices for PayBatch.');

            return;
        }

        logActivity('PayBatchCronPay: Prepared Batch → ' . json_encode($data));

        $errors   = false;
        $invalids = true;
        $uploadId = null;

        while (!$errors && $invalids && count($data) > 0) {
            try {
                $payBatchId        = $gatewayParams['payBatchID'] ?? null;
                $payBatchSecretKey = $gatewayParams['payBatchSecretKey'] ?? null;

                // Log payBatchID and per-row masked tokens before submitting to PayBatch
                $maskedRows = array_map(static function (array $row) {
                    $tok    = (string)($row[3] ?? '');
                    $masked = strlen($tok) > 8
                        ? substr($tok, 0, 4) . '****' . substr($tok, -4)
                        : '********';

                    return [
                        'invoiceId'   => $row[1] ?? null,
                        'token'       => $masked,
                        'amountCents' => $row[5] ?? null,
                    ];
                }, $data);

                logActivity(
                    'PayBatchCronPay: Submitting Auth to PayBatch'
                    . ' | payBatchID=' . $payBatchId
                    . ' | rows=' . json_encode($maskedRows)
                );

                $soap    = $payBatchSoap->getAuthRequest($data);
                $wsdl    = PAYBATCHAPIWSDL;
                $options = [
                    'trace'      => 1,
                    'login'      => $payBatchId,
                    'password'   => $payBatchSecretKey,
                    'exceptions' => true,
                ];

                $client = new SoapClient($wsdl, $options);
                $result = $client->__soapCall('Auth', [new SoapVar($soap, XSD_ANYXML)]);

                logActivity(
                    'PayBatchCronPay: Auth result fields'
                    . ' | Invalid=' . print_r($result->Invalid ?? 'n/a', true)
                    . ' | UploadID=' . print_r($result->UploadID ?? 'n/a', true)
                    . ' | InvalidReason=' . print_r($result->InvalidReason ?? 'n/a', true)
                );

                if ((int)($result->Invalid ?? 1) === 0) {
                    $invalids = false;
                    $uploadId = $result->UploadID ?? null;

                    if (empty($uploadId)) {
                        logActivity(
                            'PayBatchCronPay: Auth returned success but no UploadID. Will retry on next cron cycle.'
                        );

                        return;
                    }

                    $confirmXml = $payBatchSoap->getConfirmRequest((string)$uploadId);

                    $confirm = $client->__soapCall('Confirm', [new SoapVar($confirmXml, XSD_ANYXML)]);

                    logActivity(
                        'PayBatchCronPay: Confirm result fields'
                        . ' | Invalid=' . print_r($confirm->Invalid ?? 'n/a', true)
                        . ' | InvalidReason=' . print_r($confirm->InvalidReason ?? 'n/a', true)
                    );

                    if ((int)($confirm->Invalid ?? 1) !== 0) {
                        logActivity("PayBatchCronPay: Confirm failed for UploadID {$uploadId}.");
                        $errors = true;
                    }
                } else {
                    $invalidReasons = normalizeInvalidReasons($result->InvalidReason ?? []);

                    if (empty($invalidReasons)) {
                        logActivity(
                            'PayBatchCronPay: Auth returned invalid batch but no InvalidReason details were supplied.'
                        );
                        $errors = true;
                        break;
                    }

                    $indexesToRemove = [];

                    foreach ($invalidReasons as $invalid) {
                        $line = (int)($invalid->Line ?? 0);

                        $reason = '';
                        if (is_object($invalid)) {
                            $reason = $invalid->Reason
                                      ?? $invalid->Description
                                         ?? $invalid->Message
                                            ?? json_encode($invalid);
                        } else {
                            $reason = json_encode($invalid);
                        }

                        if ($line > 0 && isset($data[$line - 1])) {
                            logActivity(
                                "PayBatchCronPay: Removing invalid line {$line} from batch. Reason: {$reason}. Data: "
                                . json_encode($data[$line - 1])
                            );
                            $indexesToRemove[] = $line - 1;
                        } else {
                            logActivity(
                                "PayBatchCronPay: Invalid batch row reported but line could not be matched. "
                                . "Line: {$line}. Reason: {$reason}"
                            );
                        }
                    }

                    $indexesToRemove = array_unique($indexesToRemove);
                    rsort($indexesToRemove);

                    foreach ($indexesToRemove as $index) {
                        if (isset($data[$index])) {
                            unset($data[$index]);
                        }
                    }

                    $data = array_values($data);

                    if (count($data) === 0) {
                        logActivity(
                            'PayBatchCronPay: All rows were rejected during Auth; no valid invoices remain in batch.'
                        );
                    }
                }
            } catch (SoapFault $fault) {
                logActivity('PayBatchCronPay SOAP Fault: ' . $fault->getMessage());
                $errors = true;
            } catch (Throwable $e) {
                logActivity('PayBatchCronPay Exception: ' . $e->getMessage());
                $errors = true;
            }
        }

        if ($errors) {
            logActivity('PayBatchCronPay: Failed to process.');

            return;
        }

        if (empty($uploadId)) {
            logActivity('PayBatchCronPay: No UploadID generated.');

            return;
        }

        upsertPayBatchRecord('uploadid', (string)$uploadId, 'true');

        foreach ($data as $item) {
            $invoiceIdForSent = isset($item[1]) ? (int)$item[1] : 0;

            if ($invoiceIdForSent > 0) {
                upsertPayBatchRecord('sent_invoice', (string)$invoiceIdForSent, (string)$uploadId);
                clearInvoiceCycleBlock($invoiceIdForSent);

                logActivity("PayBatchCronPay: Recorded invoice {$invoiceIdForSent} as sent (UploadID {$uploadId}).");
            }
        }

        logActivity(
            count($data) . ' invoices were successfully uploaded to Payfast Gateway with PayBatch for processing'
        );
    } catch (Throwable $e) {
        logActivity('PayHostPayBatch CRON EXCEPTION: ' . $e->getMessage());
        error_log('PayHostPayBatch CRON EXCEPTION: ' . $e->getMessage());
    }
});

function getInvoices1(): array
{
    try {
        // Subquery: per user, select only their most recent unpaid invoice id.
        // This prevents charging an older open invoice when the client has
        // already paid a newer one in the same billing period.
        $latestPerUser = Capsule::table('tblinvoices')
                                ->select(Capsule::raw('MAX(id) as max_id'))
                                ->where('status', 'Unpaid')
                                ->where('paymentmethod', GATEWAY)
                                ->groupBy('userid');

        $invoices = Capsule::table('tblinvoices as i')
                           ->joinSub($latestPerUser, 'latest', 'latest.max_id', '=', 'i.id')
                           ->join('tblclients as t', 'i.userid', '=', 't.id')
                           ->join('tblpayhostpaybatchvaults as pay', 'pay.user_id', '=', 'i.userid')
                           ->select([
                                        'i.*',
                                        't.firstname',
                                        't.lastname',
                                        'pay.token as cardnum',
                                    ])
                           ->orderBy('i.id', 'asc')
                           ->get()
                           ->toArray();

        // Deduplicate by invoice id (a user with multiple vault tokens could
        // produce duplicate rows; keep the first/only match per invoice).
        $rows = array_map(static fn($row) => (array)$row, $invoices);

        $uniqueInvoices = [];
        foreach ($rows as $row) {
            $invoiceId = (int)($row['id'] ?? 0);

            if ($invoiceId > 0 && !isset($uniqueInvoices[$invoiceId])) {
                $uniqueInvoices[$invoiceId] = $row;
            }
        }

        return array_values($uniqueInvoices);
    } catch (Throwable $e) {
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

    if (file_exists($lastRunFile) && (time() - filemtime($lastRunFile)) < 3600) {
        logActivity('Testing PayBatchCronQuery: Already ran this hour; skipping.');

        return;
    }

    try {
        if (!is_dir(dirname($lastRunFile))) {
            @mkdir(dirname($lastRunFile), 0775, true);
        }

        if (@touch($lastRunFile) === false) {
            logActivity("PayBatchCronQuery: Failed to update hourly guard file: {$lastRunFile}");
        }
    } catch (Throwable $e) {
        logActivity('PayBatchCronQuery: Failed to maintain hourly guard file: ' . $e->getMessage());
    }

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

        logActivity('PayBatchCronQuery: Using payBatchID=' . $payBatchId);

        $batches = Capsule::table(DB_PREFIX . 'payhostpaybatch')
                          ->where('recordtype', 'uploadid')
                          ->pluck('recordid')
                          ->toArray();

        if (($nbatches = count($batches)) > 0) {
            logActivity('PayBatchCronQuery - Query batches: ' . json_encode($batches));

            foreach ($batches as $key => $batch) {
                $batch = (string)$batch;

                $queryResult = doPayBatchQuery(
                    $payBatchSoap,
                    $batch,
                    (string)$payBatchId,
                    (string)$payBatchSecretKey
                );

                // Detailed field-by-field log for easier troubleshooting
                if (is_object($queryResult)) {
                    logActivity(
                        "PayBatchCronQuery - Raw result for batch {$batch}"
                        . ' | Unprocessed=' . print_r($queryResult->Unprocessed ?? 'n/a', true)
                        . ' | Success=' . print_r($queryResult->Success ?? 'n/a', true)
                        . ' | TransResult=' . print_r($queryResult->TransResult ?? 'n/a', true)
                        . ' | Invalid=' . print_r($queryResult->Invalid ?? 'n/a', true)
                        . ' | InvalidReason=' . print_r($queryResult->InvalidReason ?? 'n/a', true)
                    );
                } else {
                    logActivity(
                        "PayBatchCronQuery - Non-object response for batch {$batch}: " . print_r($queryResult, true)
                    );
                }

                if (!is_object($queryResult)) {
                    $failCount = incrementBatchQueryFailCount($batch);

                    logActivity("PayBatchCronQuery: Query failed for batch {$batch}. Failure count: {$failCount}");

                    if ($failCount >= PAYBATCH_QUERY_MAX_FAILURES) {
                        timeoutBatchForCycle($batch, 'query_failed_timeout');
                    }

                    continue;
                }

                clearBatchQueryFailCount($batch);

                if ((int)($queryResult->Unprocessed ?? 1) === 0) {
                    logActivity('PayBatchCronQuery - Unprocessed: ' . ($queryResult->Unprocessed ?? 'null'));

                    if (!empty($queryResult->TransResult) && (int)($queryResult->Success ?? 0) === 1) {
                        // ── Approved ──────────────────────────────────────────────────────
                        $transResults = is_array($queryResult->TransResult)
                            ? $queryResult->TransResult
                            : [$queryResult->TransResult];

                        $allSucceeded = true;

                        foreach ($transResults as $transResult) {
                            $processed = handleLineItem($transResult);
                            if (!$processed) {
                                $allSucceeded = false;
                            }
                        }

                        if ($allSucceeded) {
                            unset($batches[$key]);

                            deleteBatchState($batch);

                            logActivity(
                                "PayBatchCronQuery: Cleared uploadid {$batch} and associated sent_invoice records."
                            );
                        } else {
                            logActivity(
                                "PayBatchCronQuery: Batch {$batch} not cleared because one or more invoice payments failed."
                            );
                        }
                    } elseif (!empty($queryResult->TransResult) && (int)($queryResult->Success ?? 0) === 0) {
                        // ── Bank / issuer decline (Success=0 but TransResult present) ────
                        // Parse every TransResult line for a human-readable decline summary.
                        $rawResults = is_array($queryResult->TransResult)
                            ? $queryResult->TransResult
                            : [$queryResult->TransResult];

                        $headings = [
                            'txId',
                            'txType',
                            'txRef',
                            'authcode',
                            'txStatusCode',
                            'txStatusDescription',
                            'txResultCode',
                            'txResultDescription',
                        ];

                        foreach ($rawResults as $raw) {
                            $parts    = explode(',', (string)$raw);
                            $combined = array_combine($headings, array_pad($parts, count($headings), ''));

                            logActivity(
                                "PayBatchCronQuery: Bank decline for batch {$batch}"
                                . " | invoiceId=" . ($combined['txRef'] ?? 'n/a')
                                . " | txId=" . ($combined['txId'] ?? 'n/a')
                                . " | statusCode=" . ($combined['txStatusCode'] ?? 'n/a')
                                . " | resultCode=" . ($combined['txResultCode'] ?? 'n/a')
                                . " | reason=" . ($combined['txResultDescription'] ?? 'n/a')
                            );
                        }

                        // Clear the batch so the invoice can be retried next cycle.
                        // Do NOT block the invoice — the decline is from the bank, not a system error.
                        deleteBatchState($batch);
                        logActivity(
                            "PayBatchCronQuery: Batch {$batch} cleared after bank decline — invoice will retry next cycle."
                        );
                    } else {
                        // ── No TransResult at all — genuine system / processing failure ──
                        logActivity(
                            "PayBatchCronQuery: Batch {$batch} finalised with no TransResult (system failure)."
                            . ' Success=' . print_r($queryResult->Success ?? null, true)
                            . ', TransResult=' . print_r($queryResult->TransResult ?? null, true)
                        );

                        timeoutBatchForCycle($batch, 'query_final_failure');
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
    } catch (Throwable $exception) {
        logActivity('PayBatchCronQuery Exception: ' . $exception->getMessage());
    }
});

/**
 * @param mixed $transResult
 *
 * @return bool
 */
function handleLineItem($transResult): bool
{
    try {
        $transResult = explode(',', (string)$transResult);
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

        $combined = array_combine($headings, $transResult);
        if (!is_array($combined)) {
            logActivity('PayBatchCronQuery: Failed to parse TransResult payload.');

            return false;
        }

        logActivity('PayBatchCronQuery - Trans Result: ' . json_encode($combined));

        $invoiceId = isset($combined['txRef']) ? (int)$combined['txRef'] : 0;

        $dataApi = [
            'invoiceid'     => $invoiceId,
            'paymentmethod' => 'payhostpaybatch',
            'status'        => 'Paid',
            'txId'          => $combined['txId'] ?? '',
        ];

        $response = updateInvoicesApi($dataApi);
        logActivity('PayBatchCronQuery - Response: ' . json_encode($response));

        if ($invoiceId <= 0 || $response === false) {
            return false;
        }

        $decoded = json_decode((string)$response, true);

        if (!is_array($decoded)) {
            logActivity(
                "PayBatchCronQuery: Failed to decode invoice update response for invoice {$invoiceId}. Raw response: "
                . (string)$response
            );

            return false;
        }

        $result = $decoded['result'] ?? '';

        if ($result !== 'success' && $result !== 'duplicate') {
            logActivity(
                "PayBatchCronQuery: Failed to mark invoice {$invoiceId} paid. API response: " . (string)$response
            );

            return false;
        }

        clearInvoiceCycleBlock($invoiceId);

        return true;
    } catch (Throwable $e) {
        logActivity('PayBatchCronQuery: handleLineItem exception: ' . $e->getMessage());

        return false;
    }
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
    $options  = [
        'trace'      => 1,
        'login'      => $payBatchId,
        'password'   => $payBatchSecretKey,
        'exceptions' => true,
    ];

    logActivity(
        "PayBatchCronQuery: SOAP Query request"
        . " | uploadId={$uploadId}"
        . " | payBatchId={$payBatchId}"
    );

    try {
        $soapClient = new SoapClient($wsdl, $options);
        $result     = $soapClient->__soapCall('Query', [new SoapVar($queryXml, XSD_ANYXML)]);


        return $result;
    } catch (SoapFault $fault) {
        logActivity("PayBatchCronQuery: SOAP fault for uploadId={$uploadId}: " . $fault->getMessage());

        return $fault->getMessage() . PHP_EOL;
    } catch (Throwable $e) {
        logActivity("PayBatchCronQuery: Exception for uploadId={$uploadId}: " . $e->getMessage());

        return $e->getMessage() . PHP_EOL;
    }
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

    if (empty($api_identifier) || empty($api_secret) || empty($api_url)) {
        logActivity('PayBatchCronQuery: Missing WHMCS API credentials for markInvoicesPaid.');

        return false;
    }

    $postFields = [
        'username'     => $api_identifier,
        'password'     => $api_secret,
        'action'       => 'UpdateInvoice',
        'status'       => 'Paid',
        'responsetype' => 'json',
        'invoiceid'    => $data['invoiceid'] ?? '',
    ];

    if (!empty($api_access_key)) {
        $postFields['accesskey'] = $api_access_key;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error === '') {
        return $response;
    }

    logActivity('PayBatchCronQuery: markInvoicesPaid curl error: ' . $error);

    return false;
}

/**
 * @param array $data
 *
 * @return bool|string
 */
function addInvoicePaymentApi(array $data): bool|string
{
    global $api_identifier, $api_secret, $api_url, $api_access_key;

    if (empty($api_identifier) || empty($api_secret) || empty($api_url)) {
        logActivity('PayBatchCronQuery: Missing WHMCS API credentials for addInvoicePaymentApi.');

        return false;
    }

    $postFields = [
        'username'     => $api_identifier,
        'password'     => $api_secret,
        'action'       => 'GetTransactions',
        'gateway'      => $data['paymentmethod'] ?? '',
        'responsetype' => 'json',
        'invoiceid'    => $data['invoiceid'] ?? '',
    ];

    if (!empty($api_access_key)) {
        $postFields['accesskey'] = $api_access_key;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        logActivity('PayBatchCronQuery: GetTransactions curl error: ' . $error);

        return false;
    }

    $decoded = json_decode((string)$response);

    if (!is_object($decoded)) {
        logActivity('PayBatchCronQuery: Invalid GetTransactions response: ' . (string)$response);

        return false;
    }

    $transactions = $decoded->transactions->transaction ?? [];

    if (!is_array($transactions)) {
        $transactions = !empty($transactions) ? [$transactions] : [];
    }

    $date = date('Y-m-d H:i:s');

    if (!empty($transactions) && !empty($transactions[0]->date)) {
        $date = $transactions[0]->date;
        logActivity('Transaction Date: ' . $date);
    }

    foreach ($transactions as $transaction) {
        if (($data['txId'] ?? '') == ($transaction->transid ?? '')) {
            logActivity(
                "PayBatchCronQuery: Duplicate TXID {$data['txId']} for invoice {$data['invoiceid']}; treating as already applied."
            );

            return json_encode(['result' => 'duplicate']);
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

    if (!empty($api_access_key)) {
        $postFields['accesskey'] = $api_access_key;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error === '') {
        return $response;
    }

    logActivity('PayBatchCronQuery: AddInvoicePayment curl error: ' . $error);

    return false;
}

/**
 * Helpers
 */
function normalizeInvalidReasons($invalidReasons): array
{
    if (empty($invalidReasons)) {
        return [];
    }

    return is_array($invalidReasons) ? $invalidReasons : [$invalidReasons];
}

function upsertPayBatchRecord(string $recordType, string $recordId, string $recordVal): void
{
    try {
        $existing = Capsule::table(DB_PREFIX . 'payhostpaybatch')
                           ->where('recordtype', $recordType)
                           ->where('recordid', $recordId)
                           ->first();

        if ($existing) {
            Capsule::table(DB_PREFIX . 'payhostpaybatch')
                   ->where('recordtype', $recordType)
                   ->where('recordid', $recordId)
                   ->update([
                                'recordval' => $recordVal,
                            ]);
        } else {
            Capsule::table(DB_PREFIX . 'payhostpaybatch')->insert([
                                                                      'recordtype' => $recordType,
                                                                      'recordid'   => $recordId,
                                                                      'recordval'  => $recordVal,
                                                                  ]);
        }
    } catch (Throwable $e) {
        logActivity("PayBatch: Failed upserting record {$recordType}/{$recordId}: " . $e->getMessage());
    }
}

function isInvoiceBlockedForCycle(int $invoiceId): bool
{
    try {
        return Capsule::table(DB_PREFIX . 'payhostpaybatch')
                      ->where('recordtype', 'cycle_blocked_invoice')
                      ->where('recordid', $invoiceId)
                      ->exists();
    } catch (Throwable $e) {
        logActivity("PayBatch: Failed checking cycle block for invoice {$invoiceId}: " . $e->getMessage());

        return false;
    }
}

function blockInvoiceForCycle(int $invoiceId, string $reason = 'query_failed_timeout'): void
{
    upsertPayBatchRecord('cycle_blocked_invoice', (string)$invoiceId, $reason);
    logActivity("PayBatch: Invoice {$invoiceId} blocked for current billing cycle. Reason: {$reason}");
}

function clearInvoiceCycleBlock(int $invoiceId): void
{
    try {
        Capsule::table(DB_PREFIX . 'payhostpaybatch')
               ->where('recordtype', 'cycle_blocked_invoice')
               ->where('recordid', $invoiceId)
               ->delete();
    } catch (Throwable $e) {
        logActivity("PayBatch: Failed clearing cycle block for invoice {$invoiceId}: " . $e->getMessage());
    }
}

function getBatchQueryFailCount(string $batch): int
{
    try {
        $row = Capsule::table(DB_PREFIX . 'payhostpaybatch')
                      ->where('recordtype', 'query_fail_count')
                      ->where('recordid', $batch)
                      ->first();

        return $row ? (int)$row->recordval : 0;
    } catch (Throwable $e) {
        logActivity("PayBatch: Failed reading query fail count for batch {$batch}: " . $e->getMessage());

        return 0;
    }
}

function setBatchQueryFailCount(string $batch, int $count): void
{
    upsertPayBatchRecord('query_fail_count', $batch, (string)$count);
}

function incrementBatchQueryFailCount(string $batch): int
{
    $count = getBatchQueryFailCount($batch) + 1;
    setBatchQueryFailCount($batch, $count);

    return $count;
}

function clearBatchQueryFailCount(string $batch): void
{
    try {
        Capsule::table(DB_PREFIX . 'payhostpaybatch')
               ->where('recordtype', 'query_fail_count')
               ->where('recordid', $batch)
               ->delete();
    } catch (Throwable $e) {
        logActivity("PayBatch: Failed clearing query fail count for batch {$batch}: " . $e->getMessage());
    }
}

function getInvoiceIdsForBatch(string $batch): array
{
    try {
        $invoiceIds = Capsule::table(DB_PREFIX . 'payhostpaybatch')
                             ->where('recordtype', 'sent_invoice')
                             ->where('recordval', $batch)
                             ->pluck('recordid')
                             ->toArray();

        return array_map(static fn($id) => (int)$id, $invoiceIds);
    } catch (Throwable $e) {
        logActivity("PayBatch: Failed getting invoice ids for batch {$batch}: " . $e->getMessage());

        return [];
    }
}

function deleteBatchState(string $batch): void
{
    try {
        Capsule::table(DB_PREFIX . 'payhostpaybatch')
               ->where('recordtype', 'uploadid')
               ->where('recordid', $batch)
               ->delete();

        Capsule::table(DB_PREFIX . 'payhostpaybatch')
               ->where('recordtype', 'sent_invoice')
               ->where('recordval', $batch)
               ->delete();

        clearBatchQueryFailCount($batch);
    } catch (Throwable $e) {
        logActivity("PayBatch: Failed deleting batch state for {$batch}: " . $e->getMessage());
    }
}

function timeoutBatchForCycle(string $batch, string $reason = 'query_failed_timeout'): void
{
    $invoiceIds = getInvoiceIdsForBatch($batch);

    foreach ($invoiceIds as $invoiceId) {
        blockInvoiceForCycle($invoiceId, $reason);
    }

    deleteBatchState($batch);

    logActivity("PayBatchCronQuery: Batch {$batch} closed for this billing cycle. Reason: {$reason}");
}
