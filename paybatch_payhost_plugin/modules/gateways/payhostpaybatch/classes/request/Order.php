<?php

namespace Payhost\classes\request;

class Order
{
    private static string $ns = 'ns1';
    private string $reference;
    private string $currency;
    private string $amount;
    private string $transDate;
    private string $locale;
    private string $pnr;
    private string $ticketNumber;
    private string $travellerType;
    private string $deliveryDate;
    private string $deliveryMethod;
    private string $installRequired;
    private string $departureAirport;
    private string $departureCountry;
    private string $departureCity;
    private string $departureDateTime;
    private string $arrivalAirport;
    private string $arrivalCountry;
    private string $arrivalCity;
    private string $arrivalDateTime;
    private string $marketingCarrierCode;
    private string $marketingCarrierName;
    private string $issuingCarrierCode;
    private string $issuingCarrierName;
    private string $flightNumber;
    private string $middleName;
    private string $telephone;
    private string $mobile;
    private string $fax;
    private string $dateOfBirth;
    private string $socialSecurity;
    private string $customerTitle;
    private string $firstName;
    private string $lastName;
    private string $email;

    /**
     * @param string $reference
     * @param string $currency
     * @param string $amount
     * @param string $transDate
     * @param string $locale
     * @param string $pnr
     * @param string $ticketNumber
     * @param string $travellerType
     * @param string $deliveryDate
     * @param string $deliveryMethod
     * @param string $installRequired
     * @param string $departureAirport
     * @param string $departureCountry
     * @param string $departureCity
     * @param string $departureDateTime
     * @param string $arrivalAirport
     * @param string $arrivalCountry
     * @param string $arrivalCity
     * @param string $arrivalDateTime
     * @param string $marketingCarrierCode
     * @param string $marketingCarrierName
     * @param string $issuingCarrierCode
     * @param string $issuingCarrierName
     * @param string $flightNumber
     * @param string $middleName
     * @param string $telephone
     * @param string $mobile
     * @param string $fax
     * @param string $dateOfBirth
     * @param string $socialSecurity
     * @param string $customerTitle
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     */
    public function __construct(
        string $reference,
        string $currency,
        string $amount,
        string $transDate,
        string $locale,
        string $customerTitle,
        string $pnr = '',
        string $ticketNumber = '',
        string $travellerType = '',
        string $deliveryDate = '',
        string $deliveryMethod = '',
        string $installRequired = '',
        string $departureAirport = '',
        string $departureCountry = '',
        string $departureCity = '',
        string $departureDateTime = '',
        string $arrivalAirport = '',
        string $arrivalCountry = '',
        string $arrivalCity = '',
        string $arrivalDateTime = '',
        string $marketingCarrierCode = '',
        string $marketingCarrierName = '',
        string $issuingCarrierCode = '',
        string $issuingCarrierName = '',
        string $flightNumber = '',
        string $middleName = '',
        string $telephone = '',
        string $mobile = '',
        string $fax = '',
        string $dateOfBirth = '',
        string $socialSecurity = '',
        string $firstName = '',
        string $lastName = '',
        string $email = ''
    ) {
        $this->reference            = $reference;
        $this->currency             = $currency;
        $this->amount               = $amount;
        $this->transDate            = $transDate;
        $this->locale               = $locale;
        $this->pnr                  = $pnr;
        $this->ticketNumber         = $ticketNumber;
        $this->travellerType        = $travellerType;
        $this->deliveryDate         = $deliveryDate;
        $this->deliveryMethod       = $deliveryMethod;
        $this->installRequired      = $installRequired;
        $this->departureAirport     = $departureAirport;
        $this->departureCountry     = $departureCountry;
        $this->departureCity        = $departureCity;
        $this->departureDateTime    = $departureDateTime;
        $this->arrivalAirport       = $arrivalAirport;
        $this->arrivalCountry       = $arrivalCountry;
        $this->arrivalCity          = $arrivalCity;
        $this->arrivalDateTime      = $arrivalDateTime;
        $this->marketingCarrierCode = $marketingCarrierCode;
        $this->marketingCarrierName = $marketingCarrierName;
        $this->issuingCarrierCode   = $issuingCarrierCode;
        $this->issuingCarrierName   = $issuingCarrierName;
        $this->flightNumber         = $flightNumber;
        $this->middleName           = $middleName;
        $this->telephone            = $telephone;
        $this->mobile               = $mobile;
        $this->fax                  = $fax;
        $this->dateOfBirth          = $dateOfBirth;
        $this->socialSecurity       = $socialSecurity;
        $this->customerTitle        = $customerTitle;
        $this->firstName            = $firstName;
        $this->lastName             = $lastName;
        $this->email                = $email;
    }

    /**
     * @param array $dataArray
     *
     * @return string
     */
    public function getOrder(array $dataArray = []): string
    {
        $namespace = self::$ns;
        $customer  = new Customer();

        return <<<XML
<!-- Order Details -->
    <$namespace:Order>
    <$namespace:MerchantOrderId>$this->reference</$namespace:MerchantOrderId>
    <$namespace:Currency>$this->currency</$namespace:Currency>
    <$namespace:Amount>$this->amount</$namespace:Amount>
    <$namespace:TransactionDate>$this->transDate</$namespace:TransactionDate>
    {$customer->getBillingDetails($dataArray)}
    {$this->getShippingDetails($dataArray)}
    {$this->getAirlineFields()}
    <$namespace:Locale>$this->locale</$namespace:Locale>
    </$namespace:Order>
XML;
    }

    /**
     * @return string
     */
    private function getAirlineFields(): string
    {
        $airline   = '';
        $namespace = self::$ns;

        if ($this->pnr != '') {
            $airline = <<<XML
<$namespace:AirlineBookingDetails>
<$namespace:TicketNumber>$this->ticketNumber</$namespace:TicketNumber>
<$namespace:PNR>$this->pnr</$namespace:PNR>
<$namespace:Passengers>
{$this->getPassenger()}
<$namespace:TravellerType>$this->travellerType</$namespace:TravellerType>
</$namespace:Passengers>
{$this->getFlightLegs()}
</$namespace:AirlineBookingDetails>
XML;
        }

        return $airline;
    }

    /**
     * @param array $dataArray
     *
     * @return string
     */
    private function getShippingDetails(array $dataArray): string
    {
        $shipping  = '';
        $namespace = self::$ns;
        $customer  = new Customer();

        if (isset($this->incShipping) || $this->deliveryDate != '' || $this->deliveryMethod != '' || isset($this->installRequired)) {
            $deliveryDate    = ($this->deliveryDate != '' ? "<$namespace:DeliveryDate>$this->deliveryDate</$namespace:DeliveryDate>" : '');
            $deliveryMethod  = ($this->deliveryMethod != '' ? "<$namespace:DeliveryMethod>$this->deliveryMethod</$namespace:DeliveryMethod>" : '');
            $installRequired = ($this->installRequired != '' ? "<$namespace:InstallationRequested>$this->installRequired</$namespace:InstallationRequested>" : '');

            $shipping = <<<XML
<$namespace:ShippingDetails>
{$customer->getCustomer($dataArray)}
{$customer->getAddress($dataArray)}
{$deliveryDate}
{$deliveryMethod}
{$installRequired}
</$namespace:ShippingDetails>
XML;
        }

        return $shipping;
    }

    /**
     * @return string
     */
    private function getFlightLegs(): string
    {
        $namespace = self::$ns;

        return <<<XML
<$namespace:FlightLegs>
<$namespace:DepartureAirport>$this->departureAirport</$namespace:DepartureAirport>
<$namespace:DepartureCountry>$this->departureCountry</$namespace:DepartureCountry>
<$namespace:DepartureCity>$this->departureCity</$namespace:DepartureCity>
<$namespace:DepartureDateTime>$this->departureDateTime</$namespace:DepartureDateTime>
<$namespace:ArrivalAirport>$this->arrivalAirport</$namespace:ArrivalAirport>
<$namespace:ArrivalCountry>$this->arrivalCountry</$namespace:ArrivalCountry>
<$namespace:ArrivalCity>$this->arrivalCity</$namespace:ArrivalCity>
<$namespace:ArrivalDateTime>$this->arrivalDateTime</$namespace:ArrivalDateTime>
<$namespace:MarketingCarrierCode>$this->marketingCarrierCode</$namespace:MarketingCarrierCode>
<$namespace:MarketingCarrierName>$this->marketingCarrierName</$namespace:MarketingCarrierName>
<$namespace:IssuingCarrierCode>$this->issuingCarrierCode</$namespace:IssuingCarrierCode>
<$namespace:IssuingCarrierName>$this->issuingCarrierName</$namespace:IssuingCarrierName>
<$namespace:FlightNumber>$this->flightNumber</$namespace:FlightNumber>
<$namespace:BaseFareAmount>$this->amount</$namespace:BaseFareAmount>
<$namespace:BaseFareCurrency>$this->currency</$namespace:BaseFareCurrency>
</$namespace:FlightLegs>
XML;
    }

    /**
     * @return string
     */
    private function getPassenger(): string
    {
        $namespace      = self::$ns;
        $middleName     = ($this->middleName != '' ? "<$namespace:MiddleName>$this->middleName</$namespace:MiddleName>" : '');
        $telephone      = ($this->telephone != '' ? "<$namespace:Telephone>$this->telephone</$namespace:Telephone>" : '');
        $mobile         = ($this->mobile != '' ? "<$namespace:Mobile>$this->mobile</$namespace:Mobile>" : '');
        $fax            = ($this->fax != '' ? "<$namespace:Fax>$this->fax</$namespace:Fax>" : '');
        $dateOfBirth    = ($this->dateOfBirth != '' ? "<$namespace:DateOfBirth>$this->dateOfBirth</$namespace:DateOfBirth>" : '');
        $socialSecurity = ($this->socialSecurity != '' ? "<$namespace:SocialSecurityNumber>$this->socialSecurity</$namespace:SocialSecurityNumber>" : '');

        return <<<XML
<$namespace:Passenger>
<$namespace:Title>$this->customerTitle</$namespace:Title>
<$namespace:FirstName>$this->firstName</$namespace:FirstName>
{$middleName}
<$namespace:LastName>$this->lastName</$namespace:LastName>
{$telephone}
{$mobile}
{$fax}
<$namespace:Email>$this->email</$namespace:Email>
{$dateOfBirth}
{$socialSecurity}
</$namespace:Passenger>

XML;
    }

}
