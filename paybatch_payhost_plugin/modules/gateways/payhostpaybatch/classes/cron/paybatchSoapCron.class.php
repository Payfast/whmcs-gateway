<?php
/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *
 * Helper class for making SOAP requests to PayBatch API
 *
 */


/**
 *
 */
class PaybatchSoapCron
{
    /**
     * @var string the url of the Payfast Gateway with PayBatch process page
     */
    public static string $processUrl = PAYBATCHAPI;

    /**
     * @var string the url of the Payfast Gateway with PayBatch WSDL
     */
    public static string $wsdl = PAYBATCHAPIWSDL;

    /**
     * @var string default namespace. We add the namespace manually because of PHP's "quirks"
     */
    private static string $ns = 'ns1';

    /**
     * @var string $notifyUrl
     */
    private static string $notifyUrl;

    /**
     * @var string $soapStart , $soapEnd
     * SOAP HEADERS
     */
    private static string $soapStart = '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">
    <SOAP-ENV:Header/>
    <SOAP-ENV:Body>';
    private static string $soapend = '</SOAP-ENV:Body>
</SOAP-ENV:Envelope>';

    /**
     * @var array of data for batchline
     */
    protected array $batchData = [];

    protected string $batchReference = 'PayBatch_';

    public function __construct()
    {
        $this->batchReference = date('Y-m-d') . '_' . uniqid();
        self::$notifyUrl      = 'https://www.xtestyz854.com';
    }

    /**
     * @param array $data
     *
     * @return array|false|string|string[]
     */
    public function getAuthRequest(array $data): string|bool
    {
        $this->batchReference = date('Y-m-d') . '_' . uniqid();
        $this->setBatchData($data);
        try {
            // Use SimpleXMLElement to build structure
            $xml = new SimpleXMLElement('<Auth />');
            $xml->addChild('BatchReference', $this->batchReference);
            $xml->addChild('NotificationUrl', self::$notifyUrl);

            $batchData = $xml->addChild('BatchData');
            foreach ($this->batchData as $line) {
                $batchLine = '';
                foreach ($line as $item) {
                    $batchLine .= $item . ',';
                }
                $batchLine = rtrim($batchLine, ',');
                $batchData->addChild('BatchLine', $batchLine);
            }

            // Remove XML headers
            $dom = new DOMDocument();
            $dom->loadXML($xml->asXML());

            $soap = $dom->saveXML($dom->documentElement);

            // Remove Auth tag - added in __soapCall
            return str_replace(['<Auth>', '</Auth>'], '', $soap);
        } catch (Exception $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @param array $data of batchline type
     */
    public function setBatchData(array $data): void
    {
        $this->batchData = [];
        foreach ($data as $line) {
            $this->batchData[] = $line;
        }
    }

    /**
     * @param $uploadId
     *
     * @return array|false|string|string[]
     */
    public function getConfirmRequest(string $uploadId): string|bool
    {
        return $this->processRequest($uploadId);
    }

    /**
     * @param $uploadId
     *
     * @return array|false|string|string[]
     */
    public function getQueryRequest(string $uploadId): string|bool
    {
        return $this->processRequest($uploadId);
    }

    /**
     * @param $uploadId
     *
     * @return array|false|string|string[]
     */
    private function processRequest(string $uploadId): string|bool
    {
        try {
            // Use SimpleXmlElement for better control of children
            $xml = new SimpleXMLElement('<Query />');

            $xml->addChild('UploadID', $uploadId);

            // Use DomDocument to remove XML headers
            $dom = new DOMDocument();
            $dom->loadXML($xml->asXML());

            $soap = $dom->saveXML($dom->documentElement);

            // Remove root tag because we pass it in the __soapCall
            return str_replace(['<Query>', '</Query>'], '', $soap);
        } catch (Exception $exception) {
            return $exception->getMessage();
        }
    }
}
