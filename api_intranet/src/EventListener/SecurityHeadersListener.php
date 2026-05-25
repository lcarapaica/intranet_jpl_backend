<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

class SecurityHeadersListener
{
    private $env;

    public function __construct(string $env)
    {
        $this->env = $env;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Only modify responses for the master (main) request
        if (!$event->isMasterRequest()) {
            return;
        }

        $response = $event->getResponse();

        // 1. Clickjacking protection
        $response->headers->set('X-Frame-Options', 'DENY');

        // 2. MIME sniffing protection
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // 3. XSS Filter protection
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // 4. Referrer leak protection
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // 5. Strict Content Security Policy (CSP) optimized for JSON APIs
        $response->headers->set('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'; object-src 'none';");

        // 6. Force HTTPS (Strict-Transport-Security)
        // Enforced in production only to prevent locking out local HTTP development
        if ($this->env === 'prod') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
    }
}
