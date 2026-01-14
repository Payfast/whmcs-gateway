<?php

namespace Payhost\classes\request;

class Risk
{
    private static string $ns = 'ns1';
    private string $riskAccNum;
    private string $riskIpAddr;

    /**
     * @param string $riskAccNum
     * @param string $riskIpAddr
     */
    public function __construct(string $riskAccNum = '', string $riskIpAddr = '')
    {
        $this->riskAccNum = $riskAccNum;
        $this->riskIpAddr = $riskIpAddr;
    }


    /**
     * @return string
     */
    public function getRisk(): string
    {
        $risk      = '';
        $namespace = self::$ns;

        if ($this->riskAccNum != '' && $this->riskIpAddr != '') {
            $risk = <<<XML
<!-- Risk Details -->
<$namespace:Risk>
<$namespace:AccountNumber>$this->riskAccNum</$namespace:AccountNumber>
<$namespace:IpV4Address>$this->riskIpAddr</$namespace:IpV4Address>
</$namespace:Risk>
XML;
        }

        return $risk;
    }

}
