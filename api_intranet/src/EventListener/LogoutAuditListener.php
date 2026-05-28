<?php

namespace App\EventListener;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * Handles audit logging for logout actions without polluting the controller.
 *
 * Uses two events:
 * - kernel.request: captures the username and token string BEFORE the token is deleted
 * - kernel.terminate: logs the LOGOUT action once we know the response was successful
 */
class LogoutAuditListener
{
    private AuditLogger $auditLogger;
    private RefreshTokenManagerInterface $refreshTokenManager;
    private UserRepository $userRepository;

    public function __construct(
        AuditLogger $auditLogger,
        RefreshTokenManagerInterface $refreshTokenManager,
        UserRepository $userRepository
    ) {
        $this->auditLogger = $auditLogger;
        $this->refreshTokenManager = $refreshTokenManager;
        $this->userRepository = $userRepository;
    }

    /**
     * Fires BEFORE the controller on POST /api/logout.
     * Looks up the refresh token and stashes the username and token string
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->getPathInfo() !== '/api/logout' || $request->getMethod() !== 'POST') {
            return;
        }

        $data = json_decode($request->getContent(), true);
        $tokenString = $data['refresh_token'] ?? null;

        if (!$tokenString) {
            return;
        }

        $token = $this->refreshTokenManager->get($tokenString);
        if ($token) {
            $request->attributes->set('_logout_username', $token->getUsername());
            $request->attributes->set('_logout_token', $tokenString);
        }
    }

    /**
     * Fires AFTER the response is sent on POST /api/logout.
     * Only logs if the request was successful (HTTP 200).
     */
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($request->getPathInfo() !== '/api/logout' || $request->getMethod() !== 'POST') {
            return;
        }

        if ($response->getStatusCode() !== 200) {
            return;
        }

        $username = $request->attributes->get('_logout_username', 'unknown');
        $tokenString = $request->attributes->get('_logout_token', null);

        // Look up the user entity to get their ID
        $user = $this->userRepository->findOneBy(['email' => $username]);
        $userId = $user instanceof User ? (string) $user->getId() : null;

        $this->auditLogger->log('LOGOUT', User::class, $userId, [
            'email'          => $username,
        ]);
    }
}
