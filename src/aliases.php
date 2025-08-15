<?php

declare(strict_types=1);

/*
 * This file defines class aliases for easier service access.
 * These aliases allow developers to type-hint against the concrete classes
 * while still using the bundle's service definitions.
 */

// Service aliases for easier dependency injection
if (!class_exists('TelemetryService')) {
    class_alias(
        \Houdini\HoudiniBundle\Service\TelemetryService::class,
        'TelemetryService'
    );
}

if (!class_exists('HttpClientService')) {
    class_alias(
        \Houdini\HoudiniBundle\Service\HttpClientService::class,
        'HttpClientService'
    );
}
