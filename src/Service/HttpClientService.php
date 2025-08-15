<?php

declare(strict_types=1);

namespace Houdini\HoudiniBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class HttpClientService
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private string $dsn;
    private array $headers;
    private int $timeout;
    private int $retryAttempts;
    private string $apiKey;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        string $dsn = '',
        int $timeout = 30,
        int $retryAttempts = 3,
        string $apiKey = ''
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->dsn = $dsn;
        $this->timeout = $timeout;
        $this->retryAttempts = $retryAttempts;
        $this->apiKey = $apiKey;

        $this->headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // Add X-Api-Key header if API key is available
        if (!empty($this->apiKey)) {
            $this->headers['X-Api-Key'] = $this->apiKey;
        }
    }

    public function postTelemetryData(array $data): bool
    {
        if (empty($this->dsn)) {
            $this->logger->warning('Houdini DSN is not configured, skipping telemetry data transmission');
            return false;
        }

        $jsonData = json_encode($data);
        if ($jsonData === false) {
            $this->logger->error('Failed to encode telemetry data as JSON');
            return false;
        }

        // Use shorter timeout and fewer retries to avoid blocking users
        $attempt = 0;
        $maxAttempts = min($this->retryAttempts, 1); // Max 1 retry to avoid blocking
        $shortTimeout = min($this->timeout, 5); // Max 5 seconds timeout

        while ($attempt <= $maxAttempts) {
            try {
                $response = $this->httpClient->request('POST', $this->dsn, [
                    'headers' => $this->headers,
                    'body' => $jsonData,
                    'timeout' => $shortTimeout,
                    'max_duration' => $shortTimeout + 1, // Hard limit
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logger->debug('Telemetry data successfully sent to backend', [
                        'status_code' => $statusCode,
                        // 'body' => $jsonData,
                        'attempt' => $attempt + 1,
                    ]);
                    return true;
                }

                $this->logger->warning('Backend returned non-success status code', [
                    'status_code' => $statusCode,
                    // 'body' => $jsonData,
                    'attempt' => $attempt + 1,
                ]);

            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Transport error while sending telemetry data', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Unexpected error while sending telemetry data', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);
            }

            $attempt++;

            // Only retry once with minimal delay
            if ($attempt <= $maxAttempts) {
                $delay = 1; // Fixed 1 second delay instead of exponential backoff
                $this->logger->info("Retrying in {$delay} second...", ['attempt' => $attempt]);
                sleep($delay);
            }
        }

        $this->logger->error('Failed to send telemetry data after attempts', [
            'total_attempts' => $maxAttempts + 1,
        ]);

        return false;
    }

    public function isConfigured(): bool
    {
        return !empty($this->dsn);
    }

    public function getDsn(): string
    {
        return $this->dsn;
    }
}
