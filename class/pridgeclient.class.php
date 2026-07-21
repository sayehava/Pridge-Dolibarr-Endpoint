<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Minimal HTTP client that submits a buffered ESC/POS ticket as a job to a Pridge Server,
 * matching its plugin API:
 *
 *   POST <url>
 *   Authorization: Bearer <endpoint token>   (omitted if no token resolved)
 *   Content-Type: application/octet-stream
 *   X-Pridge-Metadata: {"...":"..."}         (optional JSON, omitted if no metadata given)
 *   X-Pridge-Module-Version: <version>       (omitted if no version given)
 *
 *   <raw ESC/POS bytes as the request body>
 *
 * The bytes handled here are always a raw ESC/POS binary command stream (never a PDF or an
 * image file) - see README.md "Data format". Per the API's own guidance, the token is only
 * ever sent as a header, never in the URL.
 */
class PridgeClient
{
    /**
     * Module version sent as X-Pridge-Module-Version, kept in sync with modPridge::$version.
     */
    const MODULE_VERSION = '0.2.0';

    /**
     * @var int HTTP status code from the last send() call, 0 if none was attempted
     */
    public $lastHttpCode = 0;

    /**
     * @var string Response body from the last send() call
     */
    public $lastResponseBody = '';

    /**
     * @var string Server's own version, read from the last successful response, empty if unknown
     */
    public $lastServerVersion = '';

    /**
     * @var string Advisory message when this module's and the server's major versions differ,
     * empty when they match or the server did not report one
     */
    public $lastCompatibilityWarning = '';

    /**
     * Submit raw ESC/POS bytes as a job.
     *
     * @param string               $url      Full job submission URL
     * @param string               $token    Bearer token, empty to omit the Authorization header
     * @param int                  $timeout  Request timeout in seconds
     * @param string               $data     Raw ESC/POS bytes
     * @param array<string,string> $metadata Optional metadata, sent as JSON in X-Pridge-Metadata
     * @return bool True on HTTP 2xx, false otherwise (see $this->lastHttpCode/$this->lastResponseBody)
     */
    public function send($url, $token, $timeout, $data, $metadata = array())
    {
        $this->lastHttpCode = 0;
        $this->lastResponseBody = '';
        $this->lastServerVersion = '';
        $this->lastCompatibilityWarning = '';

        if (empty($url)) {
            dol_syslog("PridgeClient::send: no URL to submit to", LOG_ERR);
            return false;
        }

        $headers = array(
            'Content-Type: application/octet-stream',
            'X-Pridge-Module-Version: '.self::MODULE_VERSION,
        );
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer '.$token;
        }
        if (!empty($metadata)) {
            $headers[] = 'X-Pridge-Metadata: '.json_encode($metadata);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_RETURNTRANSFER => true,
        ));

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        $this->lastHttpCode = (int) $httpcode;
        $this->lastResponseBody = ($result !== false) ? $result : $curlerror;

        if ($result === false || $httpcode < 200 || $httpcode >= 300) {
            dol_syslog(
                "PridgeClient::send: failed url=".$url." http_code=".$httpcode." error=".$curlerror." response=".$this->lastResponseBody,
                LOG_ERR
            );
            return false;
        }

        $decoded = json_decode($this->lastResponseBody, true);
        if (is_array($decoded)) {
            if (!empty($decoded['server_version'])) {
                $this->lastServerVersion = (string) $decoded['server_version'];
            }
            if (!empty($decoded['compatibility_warning'])) {
                $this->lastCompatibilityWarning = (string) $decoded['compatibility_warning'];
                dol_syslog("PridgeClient::send: ".$this->lastCompatibilityWarning, LOG_WARNING);
            }
        }

        return true;
    }
}
