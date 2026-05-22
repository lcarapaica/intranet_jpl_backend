<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * Service to handle application audit logging (database-stored activity trails).
 * Automatically captures user email and client IP from the Symfony context.
 */
class AuditLogger
{
    private $em;
    private $security;
    private $requestStack;

    // Setting this to true pauses standard logs (useful during data seeds)
    private $isMuted = false;

    public function __construct(EntityManagerInterface $em, Security $security, RequestStack $requestStack)
    {
        $this->em = $em;
        $this->security = $security;
        $this->requestStack = $requestStack;
    }

    /**
     * Pauses standard logging.
     */
    public function mute(): void
    {
        $this->isMuted = true;
    }

    /**
     * Resumes standard logging.
     */
    public function unmute(): void
    {
        $this->isMuted = false;
    }

    /**
     * Checks if logging is currently paused.
     */
    public function isMuted(): bool
    {
        return $this->isMuted;
    }

    /**
     * Records an audit log entry.
     * 
     * @param string $action CREATE, EDIT, DELETE, LOGIN, LOGOUT, BULK_CREATE
     * @param string|null $entityName Name of the entity affected
     * @param string|null $entityId ID of the entity affected
     * @param array $details JSON serializable details about the change
     * @param string|null $forcedUserEmail Overrides automatic user email lookup (useful for system/cron tasks)
     */
    public function log(string $action, ?string $entityName = null, ?string $entityId = null, array $details = [], ?string $forcedUserEmail = null): void
    {
        if ($this->isMuted && !str_starts_with($action, 'BULK_')) {
            return;
        }

        $userEmail = $forcedUserEmail;
        if ($userEmail === null) {
            $user = $this->security->getUser();
            $userEmail = $user instanceof User ? $user->getEmail() : 'anonymous';
        }

        $request = $this->requestStack->getCurrentRequest();
        $ip = $request ? $request->getClientIp() : null;

        $log = new AuditLog();
        $log->setUserEmail($userEmail);
        $log->setAction($action);
        $log->setEntityName($entityName);
        $log->setEntityId($entityId);
        $log->setDetails($details);
        $log->setIpAddress($ip);

        $this->em->persist($log);
        $this->em->flush();
    }

    /**
     * Logs a summary of a bulk operation to avoid hitting database limits.
     */
    public function logBulk(string $entityName, int $totalCount, array $sampleData = [], string $action = 'BULK_CREATE'): void
    {
        $details = [
            'total_items' => $totalCount,
            'sample_items' => array_slice($sampleData, 0, 5), // Only first 5 items
            'info' => "Procesamiento masivo de $totalCount registros."
        ];

        $this->log($action, $entityName, 'BATCH', $details);
    }
}
