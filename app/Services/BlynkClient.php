<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use JsonException;

/**
 * Read-only client for the official Blynk Device HTTPS API.
 *
 * GET https://{server_address}/external/api/getAll?token={device_auth_token}
 * https://docs.blynk.io/en/blynk.cloud/device-https-api/get-multiple-datastream-values
 */
class BlynkClient
{
    public const READ_PATH = '/external/api/getAll';

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly Factory $http) {}

    /**
     * @return array<string, mixed>
     *
     * @throws BlynkClientException
     */
    public function readDatastreams(): array
    {
        $token = $this->configuredToken();
        $url = $this->readUrl($this->configuredBaseUrl());

        if ($token === null || $url === null) {
            throw new BlynkClientException(BlynkClientException::NOT_CONFIGURED);
        }

        try {
            $response = $this->http
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withoutRedirecting()
                ->get($url, [
                    'token' => $token,
                ]);
        } catch (ConnectionException) {
            throw new BlynkClientException(BlynkClientException::CONNECTION_FAILED);
        }

        $status = $response->status();

        if ($status === 429) {
            throw new BlynkClientException(BlynkClientException::RATE_LIMITED);
        }

        if (in_array($status, [400, 401, 403], true)) {
            throw new BlynkClientException(BlynkClientException::UNAUTHORIZED);
        }

        if ($status !== 200) {
            throw new BlynkClientException(BlynkClientException::UNAVAILABLE);
        }

        try {
            $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BlynkClientException(BlynkClientException::INVALID_RESPONSE);
        }

        if (! is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw new BlynkClientException(BlynkClientException::INVALID_RESPONSE);
        }

        return $this->normalize($decoded, $token);
    }

    private function configuredBaseUrl(): string
    {
        $baseUrl = config('services.blynk.base_url');

        return is_string($baseUrl) ? trim($baseUrl) : '';
    }

    private function configuredToken(): ?string
    {
        $token = config('services.blynk.auth_token');

        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        if ($token === '' || preg_match('/\s/', $token) === 1) {
            return null;
        }

        return $token;
    }

    private function readUrl(string $baseUrl): ?string
    {
        if ($baseUrl === '') {
            return null;
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts)) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if (($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($path !== '' && $path !== '/')) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$parts['host'].$port.self::READ_PATH;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload, string $token): array
    {
        $datastreams = [];

        foreach ($payload as $pin => $value) {
            if (! is_string($pin) || preg_match('/^[a-z]\d+$/i', $pin) !== 1) {
                continue;
            }

            $datastreams[$pin] = $this->scrub($value, $token);
        }

        if ($datastreams === [] && array_key_exists('error', $payload)) {
            throw new BlynkClientException(BlynkClientException::UNAUTHORIZED);
        }

        for ($number = 1; $number <= 9; $number++) {
            $pin = 'v'.$number;

            if (! $this->containsPin($datastreams, $pin)) {
                $datastreams[$pin] = null;
            }
        }

        uksort($datastreams, 'strnatcasecmp');

        return $datastreams;
    }

    /**
     * @param  array<string, mixed>  $datastreams
     */
    private function containsPin(array $datastreams, string $pin): bool
    {
        foreach (array_keys($datastreams) as $existing) {
            if (strcasecmp($existing, $pin) === 0) {
                return true;
            }
        }

        return false;
    }

    private function scrub(mixed $value, string $token): mixed
    {
        if (strlen($token) < 8) {
            return $value;
        }

        if (is_string($value)) {
            return str_contains($value, $token) ? null : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $scrubbed = [];

        foreach ($value as $key => $item) {
            $scrubbed[$key] = $this->scrub($item, $token);
        }

        return $scrubbed;
    }
}
