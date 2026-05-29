<?php

namespace App\EventListener;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Handles audit logging for the Refresh Token API (/api/token/refresh).
 *
 * Uses two events:
 * - kernel.request: captures the refresh token and stashes the associated username
 * - kernel.response: logs the SUCCESS or FAILURE action of the refresh token operation
 */
class RefreshTokenAuditListener
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
     * Captures the refresh token from the POST request to /api/token/refresh
     * and looks up the associated username.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->getPathInfo() !== '/api/token/refresh' || $request->getMethod() !== 'POST') {
            return;
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $tokenString = $data['refresh_token'] ?? $request->request->get('refresh_token');

        if (!$tokenString) {
            return;
        }

        // Stash masked token as an identifier
        $maskedToken = strlen($tokenString) > 8 ? substr($tokenString, 0, 8) . '...' : 'unknown';
        $request->attributes->set('_attempted_refresh_token', $maskedToken);

        // 1. Try to find the username from the database token
        $token = $this->refreshTokenManager->get($tokenString);
        if ($token) {
            $request->attributes->set('_refresh_username', $token->getUsername());
            $request->attributes->set('_refresh_source', 'database');
            return;
        }

        // 2. If token is invalid/deleted, try to extract username from expired JWT in Authorization header
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $jwt = $matches[1];
            $parts = explode('.', $jwt);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                if ($payload) {
                    $username = $payload['username'] ?? $payload['email'] ?? null;
                    if ($username) {
                        $request->attributes->set('_refresh_username', $username);
                        $request->attributes->set('_refresh_source', 'jwt_payload');
                        return;
                    }
                }
            }
        }

        // 3. Fallback: check request body for username or email
        $bodyUsername = $data['username'] ?? $data['email'] ?? null;
        if ($bodyUsername) {
            $request->attributes->set('_refresh_username', $bodyUsername);
            $request->attributes->set('_refresh_source', 'request_body');
        }
    }

    /**
     * Logs the refresh token action status.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($request->getPathInfo() !== '/api/token/refresh' || $request->getMethod() !== 'POST') {
            return;
        }

        $statusCode = $response->getStatusCode();
        $username = $request->attributes->get('_refresh_username');
        $maskedToken = $request->attributes->get('_attempted_refresh_token', 'unknown');
        $source = $request->attributes->get('_refresh_source', 'unknown');

        // Look up the user entity to get their ID if username is available
        $user = null;
        if ($username) {
            $user = $this->userRepository->findOneBy(['email' => $username]);
        }
        $userId = $user instanceof User ? (string) $user->getId() : null;
        $userEmail = $user instanceof User ? $user->getEmail() : ($username ?: 'anonymous');

        if ($statusCode === 200) {
            $this->auditLogger->log('TOKEN_REFRESH_SUCCESS', User::class, $userId, [
                'email'         => $userEmail,
                'status'        => 'success',
                'refresh_token' => $maskedToken
            ], $userEmail);
        } else {
            $details = [
                'email'         => $userEmail,
                'status'        => 'failed',
                'status_code'   => $statusCode,
                'refresh_token' => $maskedToken,
                'source'        => $source
            ];

            // Try to capture error message from the failure response
            $content = json_decode($response->getContent(), true);
            if ($content && isset($content['message'])) {
                $details['reason'] = $content['message'];
            } elseif ($content && isset($content['error'])) {
                $details['reason'] = $content['error'];
            }

            $this->auditLogger->log('TOKEN_REFRESH_FAILED', User::class, $userId, $details, $userEmail);
        }
    }
}
