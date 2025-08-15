<?php

declare(strict_types=1);

namespace Houdini\HoudiniBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

final class HttpClientService
{
    private const int DEFAULT_TIMEOUT = 30;
    private const int SUCCESS_STATUS_MIN = 200;
    private const int SUCCESS_STATUS_MAX = 299;

    private readonly array $headers;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $dsn = '',
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly string $apiKey = ''
    ) {
        $this->headers = $this->buildHeaders();
    }

    /**
     * @throws \JsonException
     */
    public function postTelemetryData(array $data): bool
    {
        if (!$this->isConfigured()) {
            $this->logger->warning('Houdini DSN is not configured, skipping telemetry data transmission');
            return false;
        }

        $jsonData = $this->encodeData($data);
        if ($jsonData === null) {
            return false;
        }

        return $this->sendRequest($jsonData);
    }

    public function isConfigured(): bool
    {
        return !empty($this->dsn);
    }

    public function getDsn(): string
    {
        return $this->dsn;
    }

    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (!empty($this->apiKey)) {
            $headers['X-Api-Key'] = $this->apiKey;
        }

        return $headers;
    }

    /**
     * @throws \JsonException
     */
    private function encodeData(array $data): ?string
    {
        $jsonData = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if ($jsonData === false) {
            $this->logger->error('Failed to encode telemetry data as JSON', [
                'json_error' => json_last_error_msg(),
            ]);
            return null;
        }

        return $jsonData;
    }

    private function sendRequest(string $jsonData): bool
    {
        try {
            $this->logger->debug('Sending telemetry data to backend', [
                'url' => $this->dsn,
                'body' => $jsonData,
                'body_size' => strlen($jsonData),
            ]);

            $response = $this->httpClient->request('POST', $this->dsn, [
                'headers' => $this->headers,
                'body' => $jsonData,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout + 1,
            ]);

            $statusCode = $response->getStatusCode();

            if ($this->isSuccessStatusCode($statusCode)) {
                $this->logger->debug('Telemetry data successfully sent to backend', [
                    'status_code' => $statusCode,
                    'response_size' => strlen($response->getContent(false)),
                ]);
                return true;
            }

            $this->logger->warning('Backend returned non-success status code', [
                'status_code' => $statusCode,
                'response_body' => $this->getResponseBodySafely($response),
                'request_body' => $jsonData,
            ]);

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Transport error while sending telemetry data', [
                'error' => $e->getMessage(),
                'dsn' => $this->dsn,
                'request_body' => $jsonData,
            ]);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HTTP error while sending telemetry data', [
                'error' => $e->getMessage(),
                'status_code' => $e->getResponse()->getStatusCode(),
                'request_body' => $jsonData,
            ]);
        } catch (\JsonException $e) {
            $this->logger->error('JSON encoding error', [
                'error' => $e->getMessage(),
                'request_body' => $jsonData,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error while sending telemetry data', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'request_body' => $jsonData,
            ]);
        }

        return false;
    }

    private function isSuccessStatusCode(int $statusCode): bool
    {
        return $statusCode >= self::SUCCESS_STATUS_MIN && $statusCode <= self::SUCCESS_STATUS_MAX;
    }

    private function getResponseBodySafely($response): string
    {
        try {
            $body = $response->getContent(false);
            return strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
        } catch (\Throwable) {
            return '[Unable to read response body]';
        }
    }
}
