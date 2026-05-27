<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class RateLimitListener
{
    private $loginLimiter;
    private $apiLimiter;

    public function __construct(RateLimiterFactory $loginLimiter, RateLimiterFactory $apiLimiter)
    {
        $this->loginLimiter = $loginLimiter;
        $this->apiLimiter = $apiLimiter;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only inspect the main request (not sub-requests)
        if (!$event->isMasterRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Check if it is an API request
        if (!str_starts_with($path, '/api')) {
            return;
        }

        // Bypass rate limiting for the bulk product creation endpoint
        if ($path === '/api/products/bulk') {
            return;
        }

        $ip = $request->getClientIp();

        // 1. Strict limit on login endpoint
        if ($path === '/api/login') {
            $limiter = $this->loginLimiter->create($ip);
            if (false === $limiter->consume(1)->isAccepted()) {
                throw new TooManyRequestsHttpException(60, 'Demasiados intentos de inicio de sesión. Por favor, intente de nuevo en un minuto.');
            }
            return;
        }

        // 2. General limit on all other API endpoints
        $limiter = $this->apiLimiter->create($ip);
        if (false === $limiter->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(60, 'Límite de solicitudes de API excedido. Por favor, espere antes de intentar de nuevo.');
        }
    }
}
