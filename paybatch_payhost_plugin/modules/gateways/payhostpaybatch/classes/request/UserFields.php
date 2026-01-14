<?php

namespace Payhost\classes\request;

class UserFields
{
    private static string $ns = 'ns1';

    public function setData($data)
    {
        foreach ($data as $key => $value) {
            $k        = $key;
            $this->$k = $value;
        }
    }

    /**
     * @return string
     */
    public function getUserFields(): string
    {
        $userDefined = '<!-- User Fields -->' . PHP_EOL;
        $i           = 1;
        $namespace   = self::$ns;

        while ($i >= 1) {
            if (isset($this->{'userKey' . $i}) && $this->{'userKey' . $i} != '' && isset($this->{'userField' . $i}) && $this->{'userField' . $i} != '') {
                $key   = $this->{'userKey' . $i};
                $value = $this->{'userField' . $i};

                $userDefined
                    .= <<<XML
    <$namespace:UserDefinedFields>
    <$namespace:key>$key</ns1:key>
    <$namespace:value>$value</ns1:value>
    </$namespace:UserDefinedFields>

XML;
                $i++;
            } else {
                break;
            }
        }

        return $userDefined;
    }

}
