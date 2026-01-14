<?php

namespace Payhost\classes\request;

class Merchant
{
    private static string $ns = 'ns1';
    protected string $pgid;
    protected string $encryptionKey;

    /**
     * @param string $pgid
     * @param string $encryptionKey
     */
    public function __construct(string $pgid, string $encryptionKey)
    {
        $this->pgid          = $pgid;
        $this->encryptionKey = $encryptionKey;
    }

    /**
     * @return string
     */
    public function getAccount(): string
    {
        $namespace = self::$ns;

        return <<<XML
<!-- Account Details -->
    <$namespace:Account>
    <$namespace:PayGateId>$this->pgid</$namespace:PayGateId>
    <$namespace:Password>$this->encryptionKey</$namespace:Password>
    </$namespace:Account>
XML;
    }

    /**
     * @return array
     */
    public function getAccountData(): array
    {
        $payGateId = $this->pgid;
        $password  = $this->encryptionKey;

        return ['PayGateId' => $payGateId, 'Password' => $password];
    }

}
