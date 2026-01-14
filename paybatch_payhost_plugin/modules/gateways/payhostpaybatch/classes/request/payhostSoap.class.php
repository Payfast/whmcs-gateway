<?php
/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * Helper class to make SOAP call to Payfast Gateway endpoint
 */

namespace Payhost\classes\request;

require_once __DIR__ . '/PayhostVault.php';
require_once __DIR__ . '/Merchant.php';
require_once __DIR__ . '/Customer.php';
require_once __DIR__ . '/PaymentType.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/Risk.php';
require_once __DIR__ . '/UserFields.php';

class PayhostSoap
{
    /**
     * @var string the url of the Payfast Gateway with PayBatch process page
     */
    public static string $processUrl = PAYHOSTAPI;

    /**
     * @var string the url of the Payfast Gateway with PayBatch WSDL
     */
    public static string $wsdl = PAYHOSTAPIWSDL;
    public static string $defaultPGID = '10011072130';

    // Standard Inputs
    public static int $defaultAmount = 3299;
    public static string $defaultCurrency = 'ZAR';
    public static string $defaultLocale = 'en-us';
    public static string $defaultEncryptionKey = 'test';
    public static string $defaultTitle = 'Mr';
    public static string $defaultFirstName = 'Payfast';
    public static string $defaultLastName = 'Test';
    public static string $defaultEmail = 'support@payfast.help';
    public static string $defaultCountry = 'ZAF';

    // Customer Details
    public static string $defaultNotifyUrl = 'http://www.gatewaymanagementservices.com/ws/gotNotify.php';
    public static string $defaultPayMethod = 'CC';
    /**
     * @var string default namespace
     */
    private static string $ns = 'ns1';

    // Airline
    protected string $target;

    protected array $data = [];

    /**
     * @param $data
     *
     * @return void
     */
    public function setData($data): void
    {
        foreach ($data as $key => $value) {
            $this->data[$key] = $value;
        }
    }

    /**
     * @return array|string|string[]|null
     */
    public function getSOAP(): array|string|null
    {
        $payhostVault = new PayhostVault($this->data['vaultId'] ?? '', $this->data['vaulting'] ?? false);
        $merchant     = new Merchant($this->data['pgid'], $this->data['encryptionKey']);
        $customer     = new Customer(
            $this->data['customerTitle'] ?? '',
            $this->data['firstName'] ?? '',
            $this->data['lastName'] ?? '',
            $this->data['email'] ?? '',
            $this->data['addressLine1'] ?? '',
            $this->data['addressLine2'] ?? '',
            $this->data['addressLine3'] ?? '',
            $this->data['city'] ?? '',
            $this->data['country'] ?? '',
            $this->data['state'] ?? '',
            $this->data['zip'] ?? '',
        );
        $paymentType  = new PaymentType();
        $order        = new Order(
            $this->data['reference'] ?? '',
            $this->data['currency'] ?? '',
            $this->data['amount'] ?? '',
            $this->data['transDate'] ?? '',
            $this->data['locale'] ?? '',
            $this->data['customerTitle'] ?? '',
        );
        $risk         = new Risk();
        $userFields   = new UserFields();
        $userFields->setData($this->data);

        $namespace = self::$ns;
        $xml       = <<<XML
<$namespace:SinglePaymentRequest>
<$namespace:WebPaymentRequest>
{$merchant->getAccount()}
{$customer->getCustomer($this->data)}
{$payhostVault->getVault()}
{$paymentType->getPaymentType()}
{$this->getRedirect()}
{$order->getOrder($this->data)}
{$risk->getRisk()}
{$userFields->getUserFields()}
</$namespace:WebPaymentRequest>
</$namespace:SinglePaymentRequest>
XML;

        // Remove empty lines to make the plain text request prettier
        return preg_replace(
            "/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/",
            "\n",
            $xml
        );
    }

    /**
     * @return array
     */
    public function getSOAPData(): array
    {
        $merchant = new Merchant($this->data['pgid'], $this->data['encryptionKey']);

        $data                                 = [];
        $data['WebPaymentRequest']            = [];
        $data['WebPaymentRequest']['Account'] = $merchant->getAccountData();

        return $data;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return 'PayhostSoap object';
    }

    /**
     * @return string
     */
    private function getRedirect(): string
    {
        $target    = (isset($this->target) && $this->target != '' ? '<' . self::$ns . ':Target>' . $this->target . '</' . self::$ns . ':Target>' : '');
        $namespace = self::$ns;
        $notifyURL = $this->data['notifyURL'];
        $retUrl    = $this->data['retUrl'];

        return <<<XML
<!-- Redirect Details -->
    <$namespace:Redirect>
    <$namespace:NotifyUrl>$notifyURL</$namespace:NotifyUrl>
    <$namespace:ReturnUrl>$retUrl</$namespace:ReturnUrl>
    {$target}
    </$namespace:Redirect>
XML;
    }

}
