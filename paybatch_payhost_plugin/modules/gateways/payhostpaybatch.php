<?php

/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * This module facilitates Payfast payments by means of Payfast Gateway / PayBatch for WHMCS clients
 *
 * Payfast Gateway is used to initialise and vault card detail, successive payments are made using the vault and PayBatch
 *
 */

// Require libraries needed for gateway module functions
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../includes/invoicefunctions.php';

require_once 'payhostpaybatch/lib/constants.php';
require_once 'payhostpaybatch/classes/request/paybatchSoap.class.php';
require_once 'payhostpaybatch/classes/request/payhostSoap.class.php';

// Function to define a constant if not already defined
/**
 * @param string $name
 * @param string $value
 *
 * @return void
 */
function defineConstant(string $name, string $value): void
{
    if (!defined($name)) {
        define($name, $value);
    }
}

// Function to ensure WHMCS context
/**
 * @return void
 */
function checkWHMCS(): void
{
    if (!defined('WHMCS')) {
        die('This file cannot be accessed directly');
    }
}

// Main execution
checkWHMCS();

use Payhost\classes\request\PayhostSoap;
use WHMCS\Database\Capsule;

defineConstant('DB_PREFIX', 'tbl');

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

/**
 * Check for existence of payhostpaybatchvaults table and create if not
 */
if (!function_exists('createPayhostpaybatchVaultTable')) {
    /**
     * @return bool
     */
    function createPayhostpaybatchVaultTable(): bool
    {
        try {
            if (!Capsule::schema()->hasTable(DB_PREFIX . 'payhostpaybatchvaults')) {
                Capsule::schema()->create(DB_PREFIX . 'payhostpaybatchvaults', function ($table) {
                    $table->increments('id');
                    $table->integer('user_id');
                    $table->string('token', 50);
                    $table->string('card_number', 50);
                    $table->string('card_expiry', 10);
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
createPayhostpaybatchVaultTable();

if (isset($_POST['INITIATE']) && $_POST['INITIATE'] == 'initiate') {
    $params = json_decode(base64_decode($_POST['jparams']), true);
    payhostpaybatch_initiate($params);
}

/**
 * Define module related meta data
 *
 * Values returned here are used to determine module related capabilities and
 * settings
 *
 * @return array
 */
function payhostpaybatch_MetaData(): array
{
    return [
        'DisplayName'                => 'Payfast Gateway / PayBatch',
        'APIVersion'                 => '1.1', // Use API Version 1.1
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage'           => true,
    ];
}

/**
 * Define gateway configuration options
 *
 *
 * @return array
 */
function payhostpaybatch_config(): array
{
    return [
        // The friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName'                => [
            'Type'  => 'System',
            'Value' => 'Payfast',
        ],
        // A text field type allows for single line text input
        'payHostID'                   => [
            'FriendlyName' => 'Terminal ID',
            'Type'         => 'text',
            'Size'         => '11',
            'Default'      => '',
            'Description'  => 'Enter your Terminal ID here',
        ],
        // A password field type allows for masked text input
        'payHostSecretKey'            => [
            'FriendlyName' => 'Encryption Key',
            'Type'         => 'password',
            'Size'         => '32',
            'Default'      => '',
            'Description'  => 'Enter your Payfast Gateway encryption key here',
        ],
        // A text field type allows for single line text input
        'payBatchID'                  => [
            'FriendlyName' => 'PayBatch ID',
            'Type'         => 'text',
            'Size'         => '11',
            'Default'      => '',
            'Description'  => 'Enter your PayBatch ID here',
        ],
        // A password field type allows for masked text input
        'payBatchSecretKey'           => [
            'FriendlyName' => 'PayBatch Secret Key',
            'Type'         => 'password',
            'Size'         => '32',
            'Default'      => '',
            'Description'  => 'Enter your PayBatch password here',
        ],
        // The yesno field type displays a single checkbox option
        'testMode'                    => [
            'FriendlyName' => 'Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Tick to enable test mode',
        ],
        // Enable or disable 3D Secure Authentication
        '3D'                          => [
            'FriendlyName' => '3D Secure Authentication',
            'Type'         => 'yesno',
            'Default'      => 'Yes',
        ],
        // Enable or disable card vaulting
        'payhostpaybatch_vaulting'    => [
            'FriendlyName' => 'Allow card vaulting',
            'Type'         => 'yesno',
            'Default'      => 'Yes',
        ],
        // Enable or disable recurring payments
        'payhostpaybatch_recurring'   => [
            'FriendlyName' => 'Allow recurring payments',
            'Type'         => 'yesno',
            'Default'      => 'Yes',
        ],
        // Enable or disable paybatch auto currency conversion
        'payhostpaybatch_autoconvert' => [
            'FriendlyName' => 'Enable auto convert to ZAR for PayBatch',
            'Type'         => 'yesno',
            'Default'      => 'Yes',
        ],
        // WHMCS API Configuration for cron jobs
        'whmcs_api_identifier'        => [
            'FriendlyName' => 'WHMCS API Identifier',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => '',
            'Description'  => 'Your WHMCS API Identifier for cron job access',
        ],
        'whmcs_api_secret'            => [
            'FriendlyName' => 'WHMCS API Secret',
            'Type'         => 'password',
            'Size'         => '50',
            'Default'      => '',
            'Description'  => 'Your WHMCS API Secret for cron job access',
        ],
        'whmcs_api_access_key'        => [
            'FriendlyName' => 'WHMCS API Access Key',
            'Type'         => 'password',
            'Size'         => '50',
            'Default'      => '',
            'Description'  => 'Your WHMCS API Access Key for cron job access',
        ],
        'whmcs_api_url'               => [
            'FriendlyName' => 'WHMCS API URL',
            'Type'         => 'text',
            'Size'         => '100',
            'Default'      => '',
            'Description'  => 'Full URL to your WHMCS API endpoint (e.g., https://yourdomain.com/includes/api.php)',
        ],
    ];
}

/**
 * Payment link
 *
 * Defines the HTML output displayed on an invoice
 *
 * @param $params
 *
 * @return string
 */
function payhostpaybatch_link($params): string
{
    $jparams  = base64_encode(json_encode($params));
    $vaulting = $params['payhostpaybatch_vaulting'] === 'on';
    $html     = '';

    // Check for values of correct format stored in tblclients->cardnum : we will use this to store the card vault id
    $vaultIds                 = [];
    $tblpayhostpaybatchvaults = DB_PREFIX . 'payhostpaybatchvaults';
    if ($vaulting) {
        $vaults       = Capsule::table($tblpayhostpaybatchvaults)
                               ->where('user_id', $params['clientdetails']['userid'])
                               ->select()
                               ->get();
        $vaultPattern = '/^[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}$/';
        foreach ($vaults as $vault) {
            if (preg_match($vaultPattern, $vault->token) == 1) {
                $vaultIds[] = $vault;
            }
        }
    }

    if ($vaulting) {
        $html = '<h4>Choose a card option</h4>';
    }

    $html .= <<<HTML
    <form method="post" action="modules/gateways/payhostpaybatch.php">
    <input type="hidden" name="INITIATE" value="initiate">
    <input type="hidden" name="jparams" value="$jparams">
HTML;
    if ($vaulting) {
        $html .= '<select name="card-token">';
        foreach ($vaultIds as $vault_id) {
            $html .= "<option value='$vault_id->id'>Use card $vault_id->card_number</option>";
        }
        $html .= <<<HTML
<option value="no-save">Use a new card and don't save it</option>
    <option value="new-save">Use a new card and save it</option>
</select>
HTML;
    }

    $html .= <<<HTML
    <input type="submit" value="Pay using Payfast">
</form>
HTML;

    return $html;
}

/**
 * Payment process
 *
 * Process payment to Payfast Gateway
 *
 * @param array $params
 *
 * @return void
 */
function payhostpaybatch_initiate(array $params): void
{
    // Check if test mode or not
    $testMode = $params['testMode'];
    if ($testMode == 'on') {
        $payHostId        = PAYGATETESTID;
        $payHostSecretKey = PAYGATETESTKEY;
    } else {
        $payHostId        = $params['payHostID'];
        $payHostSecretKey = $params['payHostSecretKey'];
    }

    $user_id = $params['clientdetails']['id'];

    $handle_card = htmlspecialchars($_POST['card-token'], ENT_QUOTES, 'UTF-8');

    $gatewayModuleName = basename(__FILE__, '.php');
    $html              = '';

    // Check if recurring payments and vaulting are allowed - if not, do not enable PayBatch
    $vaulting = $params['payhostpaybatch_vaulting'] === 'on';
    if ($handle_card === 'no-save') {
        $vaulting = false;
    }
    if ((int)$handle_card > 0) {
        $vault_id = $handle_card;
    }

    // System Parameters
    $companyName       = $params['companyname'];
    $systemUrl         = $params['systemurl'];
    $returnUrl         = $params['returnurl'];
    $langPayNow        = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName        = $params['paymentmethod'];
    $whmcsVersion      = $params['whmcsVersion'];

    // Callback urls
    $notifyUrl = $systemUrl . 'modules/gateways/callback/payhostpaybatch.php';
    $returnUrl = $systemUrl . 'modules/gateways/callback/payhostpaybatch.php';

    // Transaction date
    $transactionDate = date('Y-m-d\TH:i:s');

    // Client Parameters
    $clientDetails = getClientDetails($params);

    $firstname = $clientDetails['firstname'];
    $lastname  = $clientDetails['lastname'];
    $email     = $clientDetails['email'];
    $address1  = $clientDetails['address1'];
    $address2  = $clientDetails['address2'];
    $city      = $clientDetails['city'];
    $state     = $clientDetails['state'];
    $postcode  = $clientDetails['postcode'];
    $country   = $clientDetails['country'] == 'ZA' ? 'ZAF' : $clientDetails['country'];
    $phone     = $clientDetails['phone'];

    // Invoice Parameters
    $invoiceId    = $params['invoiceid'];
    $description  = $params['description'];
    $amount       = $params['amount'];
    $currencyCode = $params['currency'];

    // Get vault id from database
    $tblpayhostpaybatch = DB_PREFIX . 'payhostpaybatch';
    if ($vaulting) {
        $tblpayhostpaybatchvaults = DB_PREFIX . 'payhostpaybatchvaults';
        $vaultId                  = Capsule::table($tblpayhostpaybatchvaults)
                                           ->where('user_id', $params['clientdetails']['userid'])
                                           ->where('id', $vault_id ?? null)
                                           ->value('token');
        $vaultPattern             = '/^[0-9a-z]{8}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{12}$/';
        if (preg_match($vaultPattern, $vaultId) != 1) {
            $vaultId = '';
        }
    }

    // A web payment request - use Payfast Gateway WebPayment request for redirect

    $payhostSoap = new PayhostSoap();

    // Set data
    $data                  = [];
    $data['pgid']          = $payHostId;
    $data['encryptionKey'] = $payHostSecretKey;
    $data['reference']     = $invoiceId;
    $data['amount']        = intval($amount * 100);
    $data['currency']      = $currencyCode;
    $data['transDate']     = $transactionDate;
    $data['locale']        = 'en-us';
    $data['firstName']     = $firstname;
    $data['lastName']      = $lastname;
    $data['email']         = $email;
    $data['customerTitle'] = $data['customerTitle'] ?? 'Mr';
    $data['country']       = $country ?? 'ZAF';
    $data['retUrl']        = $returnUrl;
    $data['notifyURL']     = $notifyUrl;
    $data['recurring']     = false;
    $data['userKey1']      = 'user_id';
    $data['userField1']    = $user_id;
    $data['state']         = $state;
    $data['address1']      = $address1;
    $data['address2']      = $address2;
    $data['city']          = $city;
    $data['postcode']      = $postcode;
    $data['phone']         = $phone;

    if ($vaulting) {
        $data['vaulting'] = true;
    }
    if ($vaultId != '' && $vaulting) {
        $data['vaultId'] = $vaultId;
    }

    $payhostSoap->setData($data);

    $xml = $payhostSoap->getSOAP();

    sendSOAPRequest($xml, $tblpayhostpaybatch, $payHostSecretKey);
}

/**
 * @param string $xml
 * @param string $tblpayhostpaybatch
 * @param string $payHostSecretKey
 *
 * @return void
 */
function sendSOAPRequest(string $xml, string $tblpayhostpaybatch, string $payHostSecretKey): void
{
    // Use PHP SoapClient to handle request
    ini_set('soap.wsdl_cache', 0);
    try {
        $soapClient = new SoapClient(PAYHOSTAPIWSDL, ['trace' => 1]);
    } catch (SoapFault $fault) {
        echo $fault->getMessage() . PHP_EOL;

        return;
    }

    try {
        $result = $soapClient->__soapCall(
            'SinglePayment',
            [
                new SoapVar($xml, XSD_ANYXML),
            ]
        );

        if (property_exists($result->WebPaymentResponse, 'Redirect')) {
            // Redirect to Payment Portal
            // Store key values for return response

            Capsule::table($tblpayhostpaybatch)
                   ->insert(
                       [
                           'recordtype' => 'transactionrecord',
                           'recordid'   => $result->WebPaymentResponse->Redirect->UrlParams[1]->value,
                           'recordval'  => $result->WebPaymentResponse->Redirect->UrlParams[2]->value,
                           'dbid'       => time(),
                       ]
                   );

            // Delete records which are older than 24 hours
            $expiryTime = time() - 24 * 3600;
            Capsule::table($tblpayhostpaybatch)
                   ->where('dbid', '>', 1)
                   ->where('dbid', '<', $expiryTime)
                   ->delete();

            // Do redirect
            // First check that the checksum is valid
            $urlParamsdata = $result->WebPaymentResponse->Redirect->UrlParams;

            $checkSource = $urlParamsdata[0]->value;
            $checkSource .= $urlParamsdata[1]->value;
            $checkSource .= $urlParamsdata[2]->value;
            $checkSource .= $payHostSecretKey;
            $check       = md5($checkSource);

            if ($check == $urlParamsdata[3]->value) {
                $inputs = $urlParamsdata;

                echo <<<HTML
        <form action="{$result->WebPaymentResponse->Redirect->RedirectUrl}" method="post" name="payhost" id="pahost">
        <input type="hidden" name="{$inputs[0]->key}" value="{$inputs[0]->value}" />
        <input type="hidden" name="{$inputs[1]->key}" value="{$inputs[1]->value}" />
        <input type="hidden" name="{$inputs[2]->key}" value="{$inputs[2]->value}" />
        <input type="hidden" name="{$inputs[3]->key}" value="{$inputs[3]->value}" />
        </form>
        <script type="text/javascript"> document.forms['payhost'].submit();</script>
HTML;
            }
        } else {
            echo 'No redirect URL found in response.';
        }
    } catch (SoapFault $fault) {
        echo 'SOAP Fault: ' . $fault->getMessage();
    }
}

/**
 * @param array $params
 *
 * @return array
 */
function getClientDetails(array $params): array
{
    return [
        'firstname' => $params['clientdetails']['firstname'],
        'lastname'  => $params['clientdetails']['lastname'],
        'email'     => $params['clientdetails']['email'],
        'address1'  => $params['clientdetails']['address1'],
        'address2'  => $params['clientdetails']['address2'],
        'city'      => $params['clientdetails']['city'],
        'state'     => $params['clientdetails']['state'],
        'postcode'  => $params['clientdetails']['postcode'],
        'country'   => $params['clientdetails']['country'],
        'phone'     => $params['clientdetails']['phonenumber']
    ];
}

/**
 * Refund transaction
 *
 * Called when a refund is requested for a previously successful transaction
 *
 * @param array $params
 *
 * @return array Transaction response status
 */
function payhostpaybatch_refund(array $params): array
{
    // Gateway Configuration Parameters
    $accountId     = $params['accountID'];
    $secretKey     = $params['secretKey'];
    $testMode      = $params['testMode'];
    $dropdownField = $params['dropdownField'];
    $radioField    = $params['radioField'];
    $textareaField = $params['textareaField'];

    // Transaction Parameters
    $transactionIdToRefund = $params['transid'];
    $refundAmount          = $params['amount'];
    $currencyCode          = $params['currency'];

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname  = $params['clientdetails']['lastname'];
    $email     = $params['clientdetails']['email'];
    $address1  = $params['clientdetails']['address1'];
    $address2  = $params['clientdetails']['address2'];
    $city      = $params['clientdetails']['city'];
    $state     = $params['clientdetails']['state'];
    $postcode  = $params['clientdetails']['postcode'];
    $country   = $params['clientdetails']['country'];
    $phone     = $params['clientdetails']['phonenumber'];

    // System Parameters
    $companyName       = $params['companyname'];
    $systemUrl         = $params['systemurl'];
    $langPayNow        = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName        = $params['paymentmethod'];
    $whmcsVersion      = $params['whmcsVersion'];

    // Perform API call to initiate refund and interpret result

    return [
        // 'success' if successful, otherwise 'declined', 'error' for failure
        'status'  => 'success',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => $responseData ?? [],
        // Unique Transaction ID for the refund transaction
        'transid' => $refundTransactionId ?? '',
        // Optional fee amount for the fee value refunded
        'fees'    => $feeAmount ?? 0,
    ];
}

/**
 * Cancel subscription
 *
 * If the payment gateway creates subscriptions and stores the subscription
 * ID in tblhosting.subscriptionid, this function is called upon cancellation
 * or request by an admin user
 *
 * @param array $params
 *
 * @return array Transaction response status
 */
function payhostpaybatch_cancelSubscription(array $params): array
{
    // Gateway Configuration Parameters
    $accountId     = $params['accountID'];
    $secretKey     = $params['secretKey'];
    $testMode      = $params['testMode'];
    $dropdownField = $params['dropdownField'];
    $radioField    = $params['radioField'];
    $textareaField = $params['textareaField'];

    // Subscription Parameters
    $subscriptionIdToCancel = $params['subscriptionID'];

    // System Parameters
    $companyName       = $params['companyname'];
    $systemUrl         = $params['systemurl'];
    $langPayNow        = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName        = $params['paymentmethod'];
    $whmcsVersion      = $params['whmcsVersion'];

    // Perform API call to cancel subscription and interpret result

    return [
        // 'success' if successful, any other value for failure
        'status'  => 'success',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => $responseData ?? [],
    ];
}
