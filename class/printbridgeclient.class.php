<?php
/**
 * Minimal HTTP client that forwards a buffered ESC/POS ticket to a PrintBridge endpoint.
 *
 * The bytes handled here are always a raw ESC/POS binary command stream (never a PDF or an
 * image file) - see the README "Data format". The receiving endpoint is expected to relay them
 * to a real or virtual ESC/POS printer.
 */
class PrintBridgeClient
{
    /**
     * Send raw ESC/POS bytes to the endpoint configured on the given profile.
     *
     * @param ReceiptPrinterExtendedProfile $profile Profile with endpoint/token/timeout/verifyssl resolved
     * @param string                        $data    Raw ESC/POS bytes
     * @return bool True on HTTP 2xx, false otherwise (see $this->error)
     */
    public function send($profile, $data)
    {
        $endpoint = $profile->getEndpoint();
        if (empty($endpoint)) {
            dol_syslog("PrintBridgeClient::send: no endpoint configured for profile '".$profile->ref."'", LOG_ERR);
            return false;
        }

        $headers = array('Content-Type: application/octet-stream');
        $token = $profile->getToken();
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        $verifyssl = $profile->getVerifySsl();

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $profile->getTimeout(),
            CURLOPT_SSL_VERIFYPEER => $verifyssl,
            CURLOPT_SSL_VERIFYHOST => $verifyssl ? 2 : 0,
            CURLOPT_RETURNTRANSFER => true,
        ));

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlerror = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpcode < 200 || $httpcode >= 300) {
            dol_syslog(
                "PrintBridgeClient::send: failed for profile '".$profile->ref."' http_code=".$httpcode." error=".$curlerror,
                LOG_ERR
            );
            return false;
        }

        return true;
    }
}
