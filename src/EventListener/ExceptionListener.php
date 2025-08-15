<?php

declare(strict_types=1);

namespace Houdini\HoudiniBundle\EventListener;

use Houdini\HoudiniBundle\Service\TelemetryService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExceptionListener implements EventSubscriberInterface
{
    private TelemetryService $telemetryService;

    public function __construct(TelemetryService $telemetryService)
    {
        $this->telemetryService = $telemetryService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        $context = [
            'request_uri' => $request->getUri(),
            'request_method' => $request->getMethod(),
            'request_route' => $request->attributes->get('_route', 'unknown'),
            'user_agent' => $request->headers->get('User-Agent', ''),
            'remote_addr' => $request->getClientIp(),
        ];

        $this->telemetryService->recordException($exception, $context);

        // Also record as error metric
        $this->telemetryService->recordMetric(
            'errors.count',
            1.0,
            [
                'exception_class' => get_class($exception),
                'request_method' => $request->getMethod(),
                'request_route' => $request->attributes->get('_route', 'unknown'),
            ]
        );
    }
}
