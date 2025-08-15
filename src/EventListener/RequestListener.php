<?php

declare(strict_types=1);

namespace Houdini\HoudiniBundle\EventListener;

use Houdini\HoudiniBundle\Service\TelemetryService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestListener implements EventSubscriberInterface
{
    private TelemetryService $telemetryService;
    private array $requestTraces = [];

    public function __construct(TelemetryService $telemetryService)
    {
        $this->telemetryService = $telemetryService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $requestId = spl_object_hash($request);

        $traceData = $this->telemetryService->startTrace(
            sprintf('%s %s', $request->getMethod(), $request->getPathInfo()),
            [
                'http.method' => $request->getMethod(),
                'http.url' => $request->getUri(),
                'http.route' => $request->attributes->get('_route', 'unknown'),
                'http.user_agent' => $request->headers->get('User-Agent', ''),
                'http.remote_addr' => $request->getClientIp(),
            ]
        );

        $this->requestTraces[$requestId] = $traceData;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $requestId = spl_object_hash($request);

        if (!isset($this->requestTraces[$requestId])) {
            return;
        }

        $traceData = $this->requestTraces[$requestId];
        $duration = microtime(true) - $traceData['start_time'];

        $this->telemetryService->finishTrace($traceData, [
            'http.status_code' => $response->getStatusCode(),
            'http.response_size' => strlen($response->getContent()),
        ]);

        // Also record as HTTP request metric
        $this->telemetryService->recordHttpRequest(
            $request->getMethod(),
            $request->getUri(),
            $response->getStatusCode(),
            $duration,
            $request->headers->all()
        );

        // Record response time metric
        $this->telemetryService->recordMetric(
            'http.request.duration',
            $duration,
            [
                'method' => $request->getMethod(),
                'status_code' => $response->getStatusCode(),
                'route' => $request->attributes->get('_route', 'unknown'),
            ]
        );

        unset($this->requestTraces[$requestId]);
    }
}
