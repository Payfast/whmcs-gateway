<?php

namespace Payhost\classes\request;

class PaymentType
{
    private static string $ns = 'ns1';
    private string $payMethod;
    private string $payMethodDetail;

    /**
     * @param string $payMethod
     * @param string $payMethodDetail
     */
    public function __construct(string $payMethod = '', string $payMethodDetail = '')
    {
        $this->payMethod       = $payMethod;
        $this->payMethodDetail = $payMethodDetail;
    }


    /**
     * @return string
     */
    public function getPaymentType(): string
    {
        $paymentType = '';
        $namespace   = self::$ns;

        if ($this->payMethod != '' || $this->payMethodDetail != '') {
            $payMethod       = ($this->payMethod != '' ? "<$namespace:Method>$this->payMethod</$namespace:Method>" : '');
            $payMethodDetail = ($this->payMethodDetail != '' ? "<$namespace:Detail>$this->payMethodDetail</$namespace:Detail>" : '');

            $paymentType = <<<XML
<!-- Payment Type Details -->
    <$namespace:PaymentType>
    {$payMethod}
    {$payMethodDetail}
    </$namespace:PaymentType>
XML;
        }

        return $paymentType;
    }

}
