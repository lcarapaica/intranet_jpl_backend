<?php

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Repository\UserRepository;
use App\Entity\User;
use App\Service\AuditLogger;

/**
 * Handles custom responses and audit logging for both successful and failed JWT logins.
 */
class JwtAuthenticationListener
{
    private $auditLogger;
    private $userRepository;
    private $requestStack;

    public function __construct(AuditLogger $auditLogger, UserRepository $userRepository, RequestStack $requestStack)
    {
        $this->auditLogger = $auditLogger;
        $this->userRepository = $userRepository;
        $this->requestStack = $requestStack;
    }

    /**
     * Triggered on successful login.
     * Includes user info and writes a 'LOGIN' audit log.
     */
    public function onAuthenticationSuccessResponse(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $this->auditLogger->log('LOGIN', User::class, (string)$user->getId(), [
            'email' => $user->getEmail()
        ]);

        // Add custom user data to the response JSON
        $data['user'] = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'surname' => $user->getSurname(),
            'roles' => $user->getRoles(),
            'mustChangePassword' => $user->getMustChangePassword(),
        ];

        $event->setData($data);
    }

    /**
     * Triggered on failed login.
     * Extracts the attempted email and writes a 'LOGIN_FAILED' audit log.
     */
    public function onAuthenticationFailureResponse(AuthenticationFailureEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $attemptedUser = 'unknown';
        $entityId = null;

        if ($request) {
            $data = json_decode($request->getContent(), true);
            $attemptedUser = $data['email'] ?? 'unknown';

            // Try to find the user to log their specific entity ID
            if ($attemptedUser !== 'unknown') {
                $user = $this->userRepository->findOneBy(['email' => $attemptedUser]);
                if ($user instanceof User) {
                    $entityId = (string) $user->getId();
                }
            }
        }

        $this->auditLogger->log('LOGIN_FAILED', User::class, $entityId, [
            'attempted_email' => $attemptedUser,
            'message' => 'Intento de inicio de sesión fallido',
            'error' => $event->getException()->getMessageKey()
        ]);
    }
}
