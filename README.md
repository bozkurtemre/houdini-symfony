# Houdini Symfony Bundle

[![Latest Stable Version](http://poser.pugx.org/houdini/houdini-symfony/v)](https://packagist.org/packages/houdini/houdini-symfony) [![Total Downloads](http://poser.pugx.org/houdini/houdini-symfony/downloads)](https://packagist.org/packages/houdini/houdini-symfony) [![Latest Unstable Version](http://poser.pugx.org/houdini/houdini-symfony/v/unstable)](https://packagist.org/packages/houdini/houdini-symfony) [![License](http://poser.pugx.org/houdini/houdini-symfony/license)](https://packagist.org/packages/houdini/houdini-symfony) [![PHP Version Require](http://poser.pugx.org/houdini/houdini-symfony/require/php)](https://packagist.org/packages/houdini/houdini-symfony)


A Symfony bundle that acts as a telemetry collection wrapper, automatically collecting telemetry data (traces, metrics, logs, exceptions) and posting them to a configurable backend via DSN configuration, similar to Sentry's approach.

## Features

- **Custom Telemetry Collection**: Lightweight telemetry data collection without complex OpenTelemetry SDK setup
- **DSN Configuration**: Configure backend endpoint via environment variables
- **Automatic Collection**: Automatically captures HTTP requests, exceptions, and custom metrics
- **Retry Logic**: Built-in retry mechanism with exponential backoff
- **Configurable**: Flexible configuration for traces, metrics, and logs
- **Symfony Integration**: Native Symfony bundle with proper DI integration
- **Sentry-like API**: Familiar capture methods for manual error and message reporting
- **Auto-Installation**: Automatically installs configuration files when bundle is added

## Installation

```bash
composer require houdini/houdini-symfony
```

The bundle will automatically:
- Register itself in `config/bundles.php`
- Create `config/packages/houdini.yaml` configuration file
- Add environment variables to your `.env` file

## Configuration

### Environment Variables

The bundle automatically adds these to your `.env` file:

```bash
###> houdini/houdini-symfony ###
HOUDINI_DSN=https://your-backend.example.com/api/telemetry
HOUDINI_API_KEY=your-api-key
HOUDINI_SERVICE_NAME=my-app
HOUDINI_SERVICE_VERSION=1.0.0
###< houdini/houdini-symfony ###
```

### Bundle Configuration

The auto-generated `config/packages/houdini.yaml`:

```yaml
houdini:
  # DSN for backend (uses environment variable by default)
  dsn: '%env(HOUDINI_DSN)%'
  
  # API key for backend authentication (uses environment variable by default)
  api_key: '%env(HOUDINI_API_KEY)%'
  
  # Enable/disable telemetry collection
  enabled: true
  
  # Service identification
  service_name: '%env(HOUDINI_SERVICE_NAME)%'
  service_version: '%env(HOUDINI_SERVICE_VERSION)%'
  
  # Traces configuration
  traces:
    enabled: true
    sample_rate: 1.0  # 0.0 to 1.0
  
  # Metrics configuration
  metrics:
    enabled: true
    export_interval: 60  # seconds
  
  # Logs configuration
  logs:
    enabled: true
    levels: ['error', 'warning', 'info']
  
  # HTTP client configuration
  http_client:
    timeout: 30
    retry_attempts: 3
```

## Usage

### Automatic Collection

The bundle automatically collects:

- **HTTP Requests**: All incoming HTTP requests with response times and status codes
- **Exceptions**: All unhandled exceptions with context
- **Metrics**: Request duration, error counts, etc.

### Manual Usage

Inject the `TelemetryService` to manually record telemetry data:

```php
<?php

namespace App\Controller;

use Houdini\HoudiniBundle\Service\TelemetryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UserController extends AbstractController
{
    public function processUserData(TelemetryService $telemetry): JsonResponse
    {
        // Start a custom trace
        $traceData = $telemetry->startTrace('user.data.processing', [
            'user_id' => $this->getUser()?->getId(),
            'operation_type' => 'profile_update',
        ]);
        
        try {
            // Your business logic here
            $result = $this->updateUserProfile();
            
            // Record a custom metric
            $telemetry->recordMetric('user.profile.updated', 1, [
                'update_type' => 'profile_data',
                'user_role' => $this->getUser()?->getRoles()[0] ?? 'guest',
            ]);
            
            // Finish the trace
            $telemetry->finishTrace($traceData, [
                'success' => true,
                'updated_fields' => count($result),
            ]);
            
        } catch (\Exception $e) {
            // Record exception (also done automatically)
            $telemetry->recordException($e, [
                'user_id' => $this->getUser()?->getId(),
                'operation' => 'profile_update',
            ]);
            
            $telemetry->finishTrace($traceData, [
                'success' => false,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
        
        return new JsonResponse([
            'status' => 'success',
            'data' => $result
        ]);
    }
    
    private function updateUserProfile(): array
    {
        // Simulate profile update logic
        return ['name' => 'Updated', 'email' => 'user@example.com'];
    }
}
```

### Sentry-like Capture Methods

The bundle provides Sentry-like methods for manual error and message capturing:

#### Capture Messages

```php
// Capture a simple message
$telemetry->captureMessage('User login successful', 'info', [
    'user_id' => 123,
    'login_method' => 'oauth'
]);

// Capture with different levels
$telemetry->captureMessage('Cache miss occurred', 'warning', [
    'cache_key' => 'user_profile_123'
]);
```

#### Capture Errors

```php
try {
    // Some risky operation
    $this->processPayment($amount);
} catch (\Exception $e) {
    // Capture the exception manually
    $telemetry->captureError($e, [
        'user_id' => $this->getUser()->getId(),
        'payment_amount' => $amount,
        'payment_method' => 'credit_card'
    ]);
    
    // Re-throw or handle as needed
    throw $e;
}

// Or capture error messages without exception objects
$telemetry->captureErrorMessage('Payment validation failed', [
    'validation_errors' => ['amount' => 'Invalid amount'],
    'user_id' => 123
]);
```

#### Capture Breadcrumbs

```php
// Add breadcrumbs for debugging context
$telemetry->captureBreadcrumb('User started checkout process', 'navigation', 'info', [
    'cart_items' => 3,
    'total_amount' => 99.99
]);

$telemetry->captureBreadcrumb('Payment method selected', 'user_action', 'info', [
    'payment_method' => 'credit_card'
]);
```

#### Set Context and Tags

```php
// Set user context
$telemetry->setUserContext([
    'id' => 123,
    'email' => 'user@example.com',
    'role' => 'premium'
]);

// Set extra context
$telemetry->setExtraContext('feature_flags', [
    'new_checkout' => true,
    'beta_features' => false
]);

// Set tags
$telemetry->setTag('environment', 'production');
$telemetry->setTag('version', '2.1.0');
```

### Recording Custom Logs

```php
// Record custom logs
$telemetry->recordLog('info', 'User performed action', [
    'user_id' => 123,
    'action' => 'profile_update',
]);
```

### Recording Custom Metrics

```php
// Record various metrics
$telemetry->recordMetric('cache.hit.rate', 0.85);
$telemetry->recordMetric('queue.size', 42, ['queue_name' => 'emails']);
$telemetry->recordMetric('user.login.count', 1, ['method' => 'oauth']);
```

## Backend Data Format

The bundle sends data to your backend in the following JSON format:

```json
{
  "telemetry_data": [
    {
      "type": "trace",
      "operation_name": "GET /api/users",
      "trace_id": "abc123def456...",
      "span_id": "789abc...",
      "start_time": 1640995200.123,
      "end_time": 1640995200.456,
      "duration": 0.333,
      "attributes": {
        "http.method": "GET",
        "http.status_code": 200,
        "http.url": "https://example.com/api/users"
      },
      "service_name": "my-app",
      "service_version": "1.0.0"
    },
    {
      "type": "metric",
      "name": "http.request.duration",
      "value": 0.333,
      "timestamp": 1640995200.456,
      "attributes": {
        "method": "GET",
        "status_code": 200,
        "route": "api_users"
      },
      "service_name": "my-app",
      "service_version": "1.0.0"
    },
    {
      "type": "captured_error",
      "class": "App\\Exception\\ValidationException",
      "message": "Invalid input data",
      "file": "/app/src/Controller/UserController.php",
      "line": 45,
      "trace": "...",
      "timestamp": 1640995200.789,
      "context": {
        "request_uri": "/api/users",
        "user_id": 123
      },
      "service_name": "my-app",
      "service_version": "1.0.0",
      "captured_manually": true
    }
  ],
  "metadata": {
    "service_name": "my-app",
    "service_version": "1.0.0",
    "timestamp": 1640995200.999,
    "sdk_version": "1.0.0"
  }
}
```

## Configuration Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `dsn` | string | `%env(HOUDINI_DSN)%` | Backend endpoint URL |
| `api_key` | string | `%env(HOUDINI_API_KEY)%` | API key for backend authentication |
| `enabled` | boolean | `true` | Enable/disable telemetry collection |
| `service_name` | string | `%env(HOUDINI_SERVICE_NAME)%` | Service name for identification |
| `service_version` | string | `%env(HOUDINI_SERVICE_VERSION)%` | Service version |
| `traces.enabled` | boolean | `true` | Enable trace collection |
| `traces.sample_rate` | float | `1.0` | Trace sampling rate (0.0-1.0) |
| `metrics.enabled` | boolean | `true` | Enable metrics collection |
| `metrics.export_interval` | integer | `60` | Metrics export interval (seconds) |
| `logs.enabled` | boolean | `true` | Enable log collection |
| `logs.levels` | array | `['error', 'warning', 'info']` | Log levels to collect |
| `http_client.timeout` | integer | `30` | HTTP client timeout (seconds) |
| `http_client.retry_attempts` | integer | `3` | Number of retry attempts |

## Architecture

The bundle uses a simplified telemetry collection approach:

- **No Complex Dependencies**: Avoids heavy OpenTelemetry SDK setup requirements
- **Custom Trace IDs**: Generates unique trace and span IDs using `random_bytes()`
- **Direct Backend Communication**: Posts collected data directly to your configured backend
- **Lightweight**: Minimal overhead on your application performance

## Development

### Running Tests

```bash
composer install
composer test
```

### Code Style

```bash
composer cs-fix
```

### Static Analysis

```bash
composer phpstan
composer psalm
```

## License

This bundle is released under the MIT License. See the [LICENSE.md](LICENSE.md) file for details.

## Contributing

Contributions are welcome! Please read our contributing guidelines and submit pull requests to our repository.
