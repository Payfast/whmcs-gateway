<?php

namespace Payhost\classes\request;

class Customer
{
    private string $customerTitle;
    private string $firstName;
    private string $lastName;
    private string $email;
    private string $addressLine1;
    private string $addressLine2;
    private string $addressLine3;
    private string $city;
    private string $country;
    private string $state;
    private string $zip;
    private string $middleName;
    private string $telephone;
    private string $mobile;
    private string $fax;
    private string $dateOfBirth;
    private string $socialSecurity;
    private static string $ns = 'ns1';

    /**
     * @param string $customerTitle
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param string $addressLine1
     * @param string $addressLine2
     * @param string $addressLine3
     * @param string $city
     * @param string $country
     * @param string $state
     * @param string $zip
     */
    public function __construct(
        string $customerTitle = '',
        string $firstName = '',
        string $lastName = '',
        string $email = '',
        string $addressLine1 = '',
        string $addressLine2 = '',
        string $addressLine3 = '',
        string $city = '',
        string $country = '',
        string $state = '',
        string $zip = '',
        string $middleName = '',
        string $telephone = '',
        string $mobile = '',
        string $fax = '',
        string $dateOfBirth = '',
        string $socialSecurity = ''
    ) {
        $this->customerTitle  = $customerTitle;
        $this->firstName      = $firstName;
        $this->lastName       = $lastName;
        $this->email          = $email;
        $this->addressLine1   = $addressLine1;
        $this->addressLine2   = $addressLine2;
        $this->addressLine3   = $addressLine3;
        $this->city           = $city;
        $this->country        = $country;
        $this->state          = $state;
        $this->zip            = $zip;
        $this->middleName     = $middleName;
        $this->telephone      = $telephone;
        $this->mobile         = $mobile;
        $this->fax            = $fax;
        $this->dateOfBirth    = $dateOfBirth;
        $this->socialSecurity = $socialSecurity;
    }

    /**
     * @param array $dataArray
     *
     * @return string
     */
    public function getCustomer(array $dataArray): string
    {
        $middleName     = $this->formatXmlTag('middleName', 'MiddleName');
        $telephone      = $this->formatXmlTag('telephone', 'Telephone');
        $mobile         = $this->formatXmlTag('mobile', 'Mobile');
        $fax            = $this->formatXmlTag('fax', 'Fax');
        $dateOfBirth    = $this->formatXmlTag('dateOfBirth', 'DateOfBirth');
        $socialSecurity = $this->formatXmlTag('socialSecurity', 'SocialSecurityNumber');
        $address        = $this->getAddress($dataArray);
        $customerTitle  = $dataArray['customerTitle'];
        $firstName      = $dataArray['firstName'];
        $lastName       = $dataArray['lastName'];
        $email          = $dataArray['email'];
        $namespace      = self::$ns;

        return <<<XML
<!-- Customer Details -->
    <$namespace:Customer>
    <$namespace:Title>$customerTitle</$namespace:Title>
    <$namespace:FirstName>$firstName</$namespace:FirstName>
    {$middleName}
    <$namespace:LastName>$lastName</$namespace:LastName>
    {$telephone}
    {$mobile}
    {$fax}
    <$namespace:Email>$email</$namespace:Email>
    {$dateOfBirth}
    {$socialSecurity}
    {$address}
    </$namespace:Customer>
XML;
    }

    /**
     * @param string $property
     * @param string $tagName
     *
     * @return string
     */
    private function formatXmlTag(string $property, string $tagName): string
    {
        $namespace = self::$ns;
        $value     = match ($property) {
            'middleName' => $this->middleName,
            'telephone' => $this->telephone,
            'mobile' => $this->mobile,
            'fax' => $this->fax,
            'dateOfBirth' => $this->dateOfBirth,
            'socialSecurity' => $this->socialSecurity,
            default => ''
        };

        if (!empty($value)) {
            return "<$namespace:$tagName>$value</$namespace:$tagName>";
        }

        return '';
    }

    /**
     * @param array $dataArray
     *
     * @return string
     */
    public function getAddress(array $dataArray): string
    {
        $namespace = self::$ns;
        $address1  = $dataArray['address1'];
        $city      = $dataArray['city'];
        $country   = $dataArray['country'];
        $state     = $dataArray['state'];
        $zip       = $dataArray['zip'];

        return <<<XML
<!-- Address Details -->
    <$namespace:Address>
    <$namespace:AddressLine>$address1</$namespace:AddressLine>
    <$namespace:AddressLine>$this->addressLine2</$namespace:AddressLine>
    <$namespace:AddressLine>$this->addressLine3</$namespace:AddressLine>
    <$namespace:City>$city</$namespace:City>
    <$namespace:Country>$country</$namespace:Country>
    <$namespace:State>$state</$namespace:State>
    <$namespace:Zip>$zip</$namespace:Zip>
    </$namespace:Address>
XML;
    }

    /**
     * @param array $dataArray
     *
     * @return string
     */
    public function getBillingDetails(array $dataArray): string
    {
        $namespace = self::$ns;

        return <<<XML
<$namespace:BillingDetails>
{$this->getCustomer($dataArray)}
{$this->getAddress($dataArray)}
</$namespace:BillingDetails>
XML;
    }

}
