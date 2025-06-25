<?php

namespace PbdKn\cohSensorcollector;

class SimpleHttpClient
{
    /**
     * Führt eine GET-Anfrage an die angegebene URL aus.
     *
     * @param string $url Die URL der Anfrage
     * @param int $timeout Timeout in Sekunden (optional)
     * @return string|null Antwort als String oder null bei Fehler
     */
    public function get(string $url, int $timeout = 5): ?string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            // Du kannst das loggen oder eine Exception werfen
            //error_log("cURL Fehler: " . curl_error($ch));
            $response = null;
        }

        curl_close($ch);
        return $response;
    }
    /**
     * Führt eine GET-Anfrage an die angegebene URL aus.
     * decodiert die anfrage als json
     *
     * @param string $url Die URL der Anfrage
     * @param int $timeout Timeout in Sekunden (optional)
     * @return string|null Antwort als String oder null bei Fehler
     */
    public function getJson(string $url, int $timeout = 5): ?array
    {
        $raw = self::get($url, $timeout);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            //error_log("JSON Fehler: " . json_last_error_msg());
            return null;
        }

        return $decoded;
    }    
}
?>