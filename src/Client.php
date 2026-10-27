<?php
declare(strict_types=1);
namespace ZPay;

final class Client
{
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl;
    private float $timeout;
    private ?\Closure $transport;
    private const MAX_RESPONSE = 2097152;
    // BEGIN RESOURCE_PROPERTIES
    public readonly \ZPay\Resources\AccountService $account;
    public readonly \ZPay\Resources\PaymentsService $payments;
    public readonly \ZPay\Resources\DonationsService $donations;
    public readonly \ZPay\Resources\CheckoutsService $checkouts;
    public readonly \ZPay\Resources\WithdrawalsService $withdrawals;
    public readonly \ZPay\Resources\CashoutsService $cashouts;
    public readonly \ZPay\Resources\RefundsService $refunds;
    public readonly \ZPay\Resources\TransfersService $transfers;
    public readonly \ZPay\Resources\BoletosService $boletos;
    public readonly \ZPay\Resources\BillPaymentsService $billPayments;
    public readonly \ZPay\Resources\MedsService $meds;
    public readonly \ZPay\Resources\CryptoService $crypto;
    public readonly \ZPay\Resources\SubaccountsService $subaccounts;
    public readonly \ZPay\Resources\SplitsService $splits;
    // END RESOURCE_PROPERTIES

    /** $transport receives (method, url, headers, JSON body or null, timeout).
     * It returns [HTTP status, response headers, response body]. Intended for tests.
     */
    public function __construct(string $clientId, string $clientSecret,
        string $baseUrl = 'https://zpaysolution.com/api/v1', float $timeout = 30.0,
        ?\Closure $transport = null)
    {
        foreach ([$clientId, $clientSecret] as $value) {
            if (trim($value) === '' || strpbrk($value, "\r\n") !== false) throw new \InvalidArgumentException('Credenciais inválidas.');
        }
        $url = parse_url($baseUrl);
        $scheme = $url['scheme'] ?? '';
        $host = $url['host'] ?? '';
        if (!$url || !$host || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
            ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)))) {
            throw new \InvalidArgumentException('Use baseUrl HTTPS sem credenciais, query ou fragmento; HTTP apenas em localhost.');
        }
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 2147483) throw new \InvalidArgumentException('Timeout inválido.');
        $this->clientId = $clientId; $this->clientSecret = $clientSecret;
        $this->baseUrl = rtrim($baseUrl, '/'); $this->timeout = $timeout; $this->transport = $transport;
    // BEGIN RESOURCE_INITIALIZERS
        $this->account = new \ZPay\Resources\AccountService($this);
        $this->payments = new \ZPay\Resources\PaymentsService($this);
        $this->donations = new \ZPay\Resources\DonationsService($this);
        $this->checkouts = new \ZPay\Resources\CheckoutsService($this);
        $this->withdrawals = new \ZPay\Resources\WithdrawalsService($this);
        $this->cashouts = new \ZPay\Resources\CashoutsService($this);
        $this->refunds = new \ZPay\Resources\RefundsService($this);
        $this->transfers = new \ZPay\Resources\TransfersService($this);
        $this->boletos = new \ZPay\Resources\BoletosService($this);
        $this->billPayments = new \ZPay\Resources\BillPaymentsService($this);
        $this->meds = new \ZPay\Resources\MedsService($this);
        $this->crypto = new \ZPay\Resources\CryptoService($this);
        $this->subaccounts = new \ZPay\Resources\SubaccountsService($this);
        $this->splits = new \ZPay\Resources\SplitsService($this);
    // END RESOURCE_INITIALIZERS
    }

    public function __debugInfo(): array { return ['baseUrl' => $this->baseUrl, 'timeout' => $this->timeout]; }

    /** @internal Resource classes use this transport; no retry is performed. */
    public function request(string $method, string $path, ?array $data = null, array $query = []): array
    {
        $params = [];
        foreach ($query as $key => $value) {
            if ($value === null) continue;
            if (!is_scalar($value) || (is_float($value) && !is_finite($value))) throw new \InvalidArgumentException('Filtro inválido.');
            $params[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        }
        // Only SDK resource paths are accepted, never absolute user URLs.
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) throw new \InvalidArgumentException('Rota inválida.');
        $url = $this->baseUrl . $path . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
        $payload = $data === null ? null : json_encode((object)$data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json',
            'client-id' => $this->clientId, 'client-secret' => $this->clientSecret, 'User-Agent' => 'zpay-sdk-php/0.1.0'];
        if ($this->transport) {
            try { [$status, $responseHeaders, $raw] = ($this->transport)($method, $url, $headers, $payload, $this->timeout); }
            catch (ZPayError $error) { throw $error; }
            catch (\Throwable) { throw new ZPayError('Falha de conexão com a Z.PAY.', outcomeUnknown: $method !== 'GET', errorCode: 'NETWORK_ERROR'); }
        } else {
            [$status, $responseHeaders, $raw] = $this->send($method, $url, $headers, $payload);
        }
        try { $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ZPayError('Resposta JSON inválida.', status: $status, errorCode: 'INVALID_RESPONSE', outcomeUnknown: $method !== 'GET'); }
        if (strlen($raw) > self::MAX_RESPONSE || !str_starts_with(ltrim($raw), '{') || !is_array($body)) {
            throw new ZPayError('Formato de resposta inválido.', status: $status, errorCode: 'INVALID_RESPONSE', outcomeUnknown: $method !== 'GET');
        }
        if ($status < 200 || $status >= 300) {
            $body = $this->redact($body);
            $message = is_string($body['error'] ?? null) ? $body['error'] : "Erro HTTP $status na Z.PAY.";
            $code = is_string($body['code'] ?? null) ? $body['code'] : 'API_ERROR';
            $retryAfter = null;
            foreach ($responseHeaders as $name => $value) if (strtolower($name) === 'retry-after') $retryAfter = (string)$value;
            throw new ZPayError($message, $status, $code, $body, $retryAfter,
                $method !== 'GET' && ($status >= 500 || in_array($status, [408, 409, 429], true) || ($status >= 300 && $status < 400)));
        }
        return $body;
    }

    private function redact(mixed $value): mixed
    {
        if (is_string($value)) return str_replace([$this->clientSecret, $this->clientId], '[redacted]', $value);
        if (is_array($value)) return array_map(fn($item) => $this->redact($item), $value);
        return $value;
    }

    private function send(string $method, string $url, array $headers, ?string $payload): array
    {
        if (!extension_loaded('curl')) throw new \LogicException('Habilite a extensão PHP cURL.');
        $curl = curl_init($url); $responseHeaders = []; $raw = ''; $tooLarge = false;
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => (int)ceil(min($this->timeout, 10) * 1000),
            CURLOPT_TIMEOUT_MS => (int)ceil($this->timeout * 1000),
            CURLOPT_HTTPHEADER => array_map(fn($key, $value) => "$key: $value", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw, &$tooLarge): int {
                if (strlen($raw) + strlen($chunk) > self::MAX_RESPONSE) { $tooLarge = true; return 0; }
                $raw .= $chunk; return strlen($chunk);
            },
        ]);
        if ($payload !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        $result = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($result === false) throw new ZPayError($tooLarge ? 'Resposta excedeu o limite.' : 'Falha de conexão ou tempo de resposta esgotado.',
            status: $status, errorCode: $tooLarge ? 'INVALID_RESPONSE' : 'NETWORK_ERROR', outcomeUnknown: $method !== 'GET');
        return [$status, $responseHeaders, $raw];
    }
}
