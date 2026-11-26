<?php
/**
 * Minimal client for the FeatherPanel admin API.
 *
 * Every call returns a FeatherpanelApiResponse; nothing here throws on HTTP errors.
 * A transport callable can be injected so tests run without a network.
 */
class FeatherpanelApiResponse
{
    /** @var int HTTP status (0 when the request never reached the panel) */
    public $status;
    /** @var mixed Decoded JSON body or null */
    public $body;
    /** @var string Raw response body */
    public $raw;
    /** @var string|null Transport error */
    public $transportError;

    public function __construct($status, $raw, $transportError = null)
    {
        $this->status = (int) $status;
        $this->raw = (string) $raw;
        $this->transportError = $transportError;
        $decoded = json_decode($this->raw, true);
        $this->body = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    public function ok()
    {
        return $this->transportError === null
            && $this->status >= 200 && $this->status < 300
            && (!is_array($this->body) || !array_key_exists('success', $this->body) || $this->body['success'] === true);
    }

    /** @return array The "data" member of a FeatherPanel envelope */
    public function data()
    {
        return is_array($this->body) && isset($this->body['data']) && is_array($this->body['data'])
            ? $this->body['data']
            : [];
    }

    public function errorCode()
    {
        return is_array($this->body) && isset($this->body['error_code']) ? (string) $this->body['error_code'] : null;
    }

    public function errorMessage()
    {
        if ($this->transportError !== null) {
            return $this->transportError;
        }
        if (is_array($this->body)) {
            foreach (['error_message', 'message'] as $key) {
                if (!empty($this->body[$key]) && is_string($this->body[$key])) {
                    return $this->body[$key];
                }
            }
        }

        return 'HTTP ' . $this->status;
    }
}

class FeatherpanelApi
{
    private $baseUrl;
    private $apiKey;
    private $verifySsl;
    private $timeout;
    /** @var callable|null function(string $method, string $url, array $headers, ?string $body): array [status, raw, error] */
    private $transport;
    /** @var callable|null function(string $method, string $path, ?array $body, FeatherpanelApiResponse $response) */
    private $logger;

    public function __construct($baseUrl, $apiKey, $verifySsl = true, $timeout = 60, $transport = null)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->apiKey = (string) $apiKey;
        $this->verifySsl = (bool) $verifySsl;
        $this->timeout = (int) $timeout;
        $this->transport = $transport;
    }

    public function setLogger(callable $logger)
    {
        $this->logger = $logger;
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    public function get($path, array $query = [])
    {
        if (!empty($query)) {
            $path .= (strpos($path, '?') === false ? '?' : '&') . http_build_query($query);
        }

        return $this->request('GET', $path);
    }

    public function put($path, array $body = [])
    {
        return $this->request('PUT', $path, $body);
    }

    public function post($path, array $body = [])
    {
        return $this->request('POST', $path, $body);
    }

    public function patch($path, array $body = [])
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete($path, ?array $body = null)
    {
        return $this->request('DELETE', $path, $body);
    }

    public function request($method, $path, ?array $body = null)
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: FeatherPanel-Blesta/1.0',
        ];
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);

        if ($this->transport !== null) {
            list($status, $raw, $error) = array_pad((array) call_user_func($this->transport, $method, $url, $headers, $payload), 3, null);
        } else {
            list($status, $raw, $error) = $this->curl($method, $url, $headers, $payload);
        }

        $response = new FeatherpanelApiResponse($status, $raw, $error);
        if ($this->logger !== null) {
            call_user_func($this->logger, $method, $path, $body, $response);
        }

        return $response;
    }

    private function curl($method, $url, array $headers, $payload)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $error = $raw === false ? (curl_error($ch) ?: 'Request failed') : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        return [$status, $raw === false ? '' : $raw, $error];
    }
}
