<?php

declare(strict_types=1);

namespace Payhost\classes\request;

class UserFields
{
    private static string $ns = 'ns1';

    /**
     * Stores extracted userKey/userField pairs.
     * Using an explicit array avoids deprecated dynamic property creation (PHP 8.2+).
     *
     * @var array<int, array{key: string, value: string}>
     */
    private array $fields = [];

    /**
     * Accepts the full data array and extracts userKey/userField pairs.
     * Only pairs where both key and value are non-empty strings are stored.
     *
     * @param array $data
     *
     * @return void
     */
    public function setData(array $data): void
    {
        $this->fields = [];
        $i            = 1;

        while (isset($data['userKey' . $i], $data['userField' . $i])) {
            $key   = (string)$data['userKey' . $i];
            $value = (string)$data['userField' . $i];

            if ($key !== '' && $value !== '') {
                $this->fields[] = ['key' => $key, 'value' => $value];
            }

            $i++;
        }
    }

    /**
     * @return string
     */
    public function getUserFields(): string
    {
        $userDefined = '<!-- User Fields -->' . PHP_EOL;
        $namespace   = self::$ns;

        foreach ($this->fields as $field) {
            $key   = $field['key'];
            $value = $field['value'];

            $userDefined .= <<<XML
    <$namespace:UserDefinedFields>
    <$namespace:key>$key</ns1:key>
    <$namespace:value>$value</ns1:value>
    </$namespace:UserDefinedFields>

XML;
        }

        return $userDefined;
    }

}
