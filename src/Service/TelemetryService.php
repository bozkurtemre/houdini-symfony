<?php

declare(strict_types=1);

namespace Houdini\HoudiniBundle\Service;

use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use Psr\Log\LoggerInterface;

final class TelemetryService
{
    private TracerProviderInterface $tracerProvider;
    private TracerInterface $tracer;
    private MeterProviderInterface $meterProvider;
    private MeterInterface $meter;
    private HttpClientService $httpClient;
    private LoggerInterface $logger;
    private array $config;
    private string $serviceName;
    private string $serviceVersion;
    private string $projectId;
    private array $collectedData;

    public function __construct(
        HttpClientService $httpClient,
        LoggerInterface $logger,
        array $config,
        string $serviceName,
        string $serviceVersion,
        string $projectId = ''
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->config = $config;
        $this->serviceName = $serviceName;
        $this->serviceVersion = $serviceVersion;
        $this->projectId = $projectId;
        $this->collectedData = [];

        $this->initializeProviders();
    }

    private function initializeProviders(): void
    {
        // For now, we'll skip OpenTelemetry provider initialization
        // and focus on our custom telemetry collection
        // This avoids the complex OpenTelemetry SDK setup requirements

        // The bundle will work without OpenTelemetry providers
        // by collecting data directly and sending to backend
    }

    public function startTrace(string $operationName, array $attributes = []): array
    {
        if (!$this->config['traces']['enabled']) {
            return [];
        }

        // Generate custom trace and span IDs
        $traceId = bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));

        $traceData = [
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'trace',
                'operation_name' => $operationName,
                'trace_id' => $traceId,
                'span_id' => $spanId,
                'attributes' => $attributes,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ];

        return $traceData;
    }

    public function finishTrace(array $traceData, array $additionalAttributes = []): void
    {
        if (!$this->config['traces']['enabled'] || empty($traceData)) {
            return;
        }

        $traceData['end_time'] = microtime(true);
        $traceData['duration'] = $traceData['end_time'] - $traceData['start_time'];
        $traceData['attributes'] = array_merge($traceData['attributes'], $additionalAttributes);

        $this->collectData($traceData);
    }

    public function recordMetric(string $name, float $value, array $attributes = []): void
    {
        if (!$this->config['metrics']['enabled']) {
            return;
        }

        $metricData = [
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'metric',
                'name' => $name,
                'value' => $value,
                'attributes' => $attributes,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ];

        $this->collectData($metricData);
    }

    public function recordLog(string $level, string $message, array $context = []): void
    {
        if (!$this->config['logs']['enabled'] || !in_array($level, $this->config['logs']['levels'])) {
            return;
        }

        $logData = [
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'log',
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ];

        $this->collectData($logData);
    }

    public function recordException(\Throwable $exception, array $context = []): void
    {
        $exceptionData = [
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'exception',
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'context' => $context,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ];

        $this->collectData($exceptionData);
    }

    public function recordHttpRequest(
        string $method,
        string $url,
        int $statusCode,
        float $duration,
        array $headers = []
    ): void {
        $requestData = [
            'type' => 'http_request',
            'method' => $method,
            'url' => $url,
            'status_code' => $statusCode,
            'duration' => $duration,
            'timestamp' => microtime(true),
            'headers' => $this->sanitizeHeaders($headers),
            'service_name' => $this->serviceName,
            'service_version' => $this->serviceVersion,
            'project_id' => $this->projectId,
        ];

        $this->collectData($requestData);
    }

    private function collectData(array $data): void
    {
        $this->collectedData[] = $data;

        $this->flush();

        // Auto-flush if we have too much data
        // if (count($this->collectedData) >= 100) {
        //     $this->flush();
        // }
    }

    public function flush(): bool
    {
        if (empty($this->collectedData)) {
            return true;
        }

        $dataToSend = $this->collectedData;
        $this->collectedData = []; // Clear immediately to avoid blocking

        // Send data in background to avoid blocking user
        $this->sendDataInBackground($dataToSend);

        return true; // Return immediately, don't wait for HTTP response
    }

    private function sendDataInBackground(array $data): void
    {
        // Send each telemetry item individually as direct JSON objects
        foreach ($data as $item) {
            if (function_exists('pcntl_fork')) {
                $pid = pcntl_fork();
                if ($pid == 0) {
                    // Child process - send individual item
                    $this->httpClient->postTelemetryData($item);
                    exit(0);
                }
            } else {
                // Fallback: send synchronously but with reduced timeout
                $this->httpClient->postTelemetryData($item);
            }
        }
    }

    public function getCollectedDataCount(): int
    {
        return count($this->collectedData);
    }

    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key', 'x-auth-token'];
        $sanitized = [];

        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), $sensitiveHeaders)) {
                $sanitized[$name] = '[REDACTED]';
            } else {
                $sanitized[$name] = $value;
            }
        }

        return $sanitized;
    }

    public function __destruct()
    {
        // Flush any remaining data on destruction
        if (!empty($this->collectedData)) {
            $this->flush();
        }
    }

    /**
     * Capture a custom message (similar to Sentry's captureMessage)
     */
    public function captureMessage(string $message, string $level = 'info', array $context = []): void
    {
        $messageData = [
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'message',
                'message' => $message,
                'level' => $level,
                'context' => $context,
                'captured_manually' => true,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ];

        $this->collectData($messageData);

        // Also log it if logs are enabled
        if ($this->config['logs']['enabled'] && in_array($level, $this->config['logs']['levels'])) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Capture an error/exception (similar to Sentry's captureException)
     */
    public function captureError(\Throwable $error, array $context = []): void
    {
        $errorData = [
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'captured_error',
                'class' => get_class($error),
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
                'context' => $context,
                'captured_manually' => true,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ];

        $this->collectData($errorData);

        // Also record as error metric
        $this->recordMetric('errors.captured.count', 1.0, [
            'exception_class' => get_class($error),
            'manually_captured' => true,
        ]);

        // Log the error
        $this->logger->error('Manually captured error: ' . $error->getMessage(), [
            'exception' => $error,
            'context' => $context,
        ]);
    }

    /**
     * Capture a simple error message without exception object
     */
    public function captureErrorMessage(string $errorMessage, array $context = []): void
    {
        $errorData = [
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'captured_error_message',
                'message' => $errorMessage,
                'context' => $context,
                'captured_manually' => true,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ];

        $this->collectData($errorData);

        // Also record as error metric
        $this->recordMetric('errors.captured.count', 1.0, [
            'type' => 'error_message',
            'manually_captured' => true,
        ]);

        // Log the error
        $this->logger->error('Manually captured error message: ' . $errorMessage, $context);
    }

    /**
     * Capture breadcrumb (similar to Sentry's addBreadcrumb)
     */
    public function captureBreadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
    {
        $breadcrumbData = [
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'breadcrumb',
                'message' => $message,
                'category' => $category,
                'level' => $level,
                'data' => $data,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ];

        $this->collectData($breadcrumbData);
    }

    /**
     * Set user context for subsequent captures
     */
    public function setUserContext(array $userContext): void
    {
        $this->collectData([
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'user_context',
                'user_data' => $userContext,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ]);
    }

    /**
     * Set extra context for subsequent captures
     */
    public function setExtraContext(string $key, $value): void
    {
        $this->collectData([
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'extra_context',
                'key' => $key,
                'value' => $value,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ]);
    }

    /**
     * Set tags for subsequent captures
     */
    public function setTag(string $key, string $value): void
    {
        $this->collectData([
            'project_id' => $this->projectId,
            'telemetry_data' => [
                'type' => 'tag',
                'key' => $key,
                'value' => $value,
                'timestamp' => microtime(true),
            ],
            'metadata' => [
                'service_name' => $this->serviceName,
                'service_version' => $this->serviceVersion,
                'timestamp' => microtime(true),
            ],
        ]);
    }
}
