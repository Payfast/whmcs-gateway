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

namespace Payhost\classes\request;

/**
 *
 */
class PaybatchSoap
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
     * @var string default namespace
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

    /**
     * @param $notifyUrl
     */
    public function __construct(string $notifyUrl)
    {
        $this->batchReference = date('Y-m-d') . '_' . uniqid();
        self::$notifyUrl      = $notifyUrl;
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
     * @param $rootElement
     * @param $uploadId
     *
     * @return array|string|string[]
     */
    private function processRequest(string $rootElement, string $uploadId): array|string
    {
        try {
            $soap = $this->createSoapRequest($rootElement, $uploadId);

            return $this->removeRootTag($soap, $rootElement);
        } catch (Exception $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * Request the query
     *
     * @param $uploadId
     *
     * @return array|string|string[]
     */
    public function getQueryRequest(string $uploadId): array|string
    {
        try {
            return $this->createAndProcessRequest('Query', ['UploadID' => $uploadId]);
        } catch (Exception $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @param $uploadId
     *
     * @return array|string|string[]
     */
    public function getConfirmRequest(string $uploadId): array|string
    {
        try {
            return $this->createAndProcessRequest('Query', ['UploadID' => $uploadId]);
        } catch (Exception $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @param $data
     *
     * @return array|string|string[]
     */
    public function getAuthRequest(array $data): array|string
    {
        $this->batchReference = date('Y-m-d') . '_' . uniqid();
        $this->setBatchData($data);
        try {
            $authData = [
                'BatchReference'  => $this->batchReference,
                'NotificationUrl' => self::$notifyUrl,
                'BatchData'       => $this->prepareBatchData($this->batchData)
            ];

            return $this->createAndProcessRequest('Auth', $authData);
        } catch (Exception $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @param $rootElement
     * @param $data
     *
     * @return array|string|string[]
     */
    private function createAndProcessRequest(string $rootElement, array $data): array|string
    {
        $xml = new SimpleXMLElement("<$rootElement />");
        $this->addDataToXml($xml, $data);

        $dom = new DOMDocument();
        $dom->loadXML($xml->asXML());

        $soap = $dom->saveXML($dom->documentElement);

        return $this->removeRootTag($soap, $rootElement);
    }

    /**
     * @param $xml
     * @param $data
     *
     * @return void
     */
    private function addDataToXml(\SimpleXMLElement $xml, array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $child = $xml->addChild($key);
                $this->addDataToXml($child, $value);
            } else {
                $xml->addChild($key, (string)$value);
            }
        }
    }

    /**
     * @param $xml
     * @param $rootTag
     *
     * @return array|string|string[]
     */
    private function removeRootTag(string $xml, string $rootTag): array|string
    {
        return str_replace(["<$rootTag>", "</$rootTag>"], '', $xml);
    }

    /**
     * @param $batchData
     *
     * @return array
     */
    private function prepareBatchData(array $batchData): array
    {
        $result = [];
        foreach ($batchData as $line) {
            $batchLine = '';
            foreach ($line as $item) {
                $batchLine .= $item . ',';
            }
            $result[] = rtrim($batchLine, ',');
        }

        return $result;
    }


}
