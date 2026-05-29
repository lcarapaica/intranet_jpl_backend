<?php

namespace App\EventListener;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

class DatabaseActivitySubscriber implements EventSubscriber
{
    private $security;
    private $requestStack;
    private $auditLogger;

    public function __construct(Security $security, RequestStack $requestStack, \App\Service\AuditLogger $auditLogger)
    {
        $this->security = $security;
        $this->requestStack = $requestStack;
        $this->auditLogger = $auditLogger;
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
            Events::postUpdate,
            Events::preRemove,
        ];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->logActivity('CREATE', $args);
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->logActivity('EDIT', $args);
    }

    public function preRemove(LifecycleEventArgs $args): void
    {
        $this->logActivity('DELETE', $args);
    }

    private function logActivity(string $action, LifecycleEventArgs $args): void
    {
        if ($this->auditLogger->isMuted()) {
            return;
        }

        $entity = $args->getObject();

        // Skip logging for certain entities to avoid noise or infinite loops
        if (
            $entity instanceof AuditLog ||
            $entity instanceof \App\Entity\RefreshToken ||
            $entity instanceof \App\Entity\ConversationParticipant
        ) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $user = $this->security->getUser();
        $userEmail = $user instanceof User ? $user->getEmail() : 'anonymous';

        $request = $this->requestStack->getCurrentRequest();
        $ip = $request ? $request->getClientIp() : null;

        $log = new AuditLog();
        $log->setUserEmail($userEmail);
        $log->setAction($action);
        
        $log->setEntityName(get_class($entity));
        
        $log->setEntityId(method_exists($entity, 'getId') ? (string)$entity->getId() : null);
        $log->setIpAddress($ip);

        // Capture details for EDIT actions (which fields changed)
        if ($action === 'EDIT' && $entityManager instanceof EntityManagerInterface) {
            $uow = $entityManager->getUnitOfWork();
            $changes = $uow->getEntityChangeSet($entity);

            // Skip Conversation edit log if the only change is the 'updatedAt' field (e.g. from new messages)
            if ($entity instanceof \App\Entity\Conversation) {
                $changeFields = array_keys($changes);
                if (count($changeFields) === 1 && $changeFields[0] === 'updatedAt') {
                    return;
                }
            }
            
            // Detect Soft Delete: if 'deletedAt' changed from null to something else
            if (isset($changes['deletedAt']) && $changes['deletedAt'][0] === null && $changes['deletedAt'][1] !== null) {
                $action = 'DELETE';
            }

            // Detect Recovery: if 'deletedAt' changed from something else back to null
            if (isset($changes['deletedAt']) && $changes['deletedAt'][0] !== null && $changes['deletedAt'][1] === null) {
                $action = 'RECOVER';
            }
            
            // Censor sensitive fields (like hashed passwords) to protect security
            if (isset($changes['password'])) {
                $changes['password'] = ['[REDACTED]', '[REDACTED]'];
            }

            // Format changes to keep them clean, readable, and free of serialized entity/datetime objects
            foreach ($changes as $fieldName => $values) {
                if (is_array($values) && count($values) === 2) {
                    [$oldVal, $newVal] = $values;
                    
                    // Format DateTime
                    if ($oldVal instanceof \DateTimeInterface) {
                        $oldVal = $oldVal->format('Y-m-d H:i:s');
                    }
                    if ($newVal instanceof \DateTimeInterface) {
                        $newVal = $newVal->format('Y-m-d H:i:s');
                    }
                    
                    // Format associated entities (objects with getId)
                    if (is_object($oldVal) && method_exists($oldVal, 'getId')) {
                        $oldVal = get_class($oldVal) . '#' . $oldVal->getId();
                    }
                    if (is_object($newVal) && method_exists($newVal, 'getId')) {
                        $newVal = get_class($newVal) . '#' . $newVal->getId();
                    }
                    
                    $changes[$fieldName] = [$oldVal, $newVal];
                }
            }
            
            $log->setAction($action);
            $log->setDetails($changes);
        }

        // Capture state for CREATE, DELETE, and RECOVER actions
        if ($action === 'CREATE' || $action === 'DELETE' || $action === 'RECOVER') {
            $data = $log->getDetails() ?: [];
            
            if ($entityManager instanceof EntityManagerInterface) {
                $meta = $entityManager->getClassMetadata(get_class($entity));
                
                // Capture all basic database fields (strings, ints, datetimes, etc.)
                foreach ($meta->getFieldNames() as $fieldName) {
                    if ($fieldName === 'password') {
                        $data[$fieldName] = '[REDACTED]';
                        continue;
                    }
                    $getter = 'get' . ucfirst($fieldName);
                    if (method_exists($entity, $getter)) {
                        $val = $entity->$getter();
                        if ($val instanceof \DateTimeInterface) {
                            $data[$fieldName] = $val->format('Y-m-d H:i:s');
                        } else {
                            $data[$fieldName] = $val;
                        }
                    }
                }
                
                // Capture foreign key / relation IDs (e.g. user_id, conversation_id)
                foreach ($meta->getAssociationNames() as $assocName) {
                    $getter = 'get' . ucfirst($assocName);
                    if (method_exists($entity, $getter)) {
                        $associatedEntity = $entity->$getter();
                        if ($associatedEntity && method_exists($associatedEntity, 'getId')) {
                            $data[$assocName . '_id'] = $associatedEntity->getId();
                        }
                    }
                }
            } else {
                // Fallback if EntityManager is not available
                if (method_exists($entity, 'getEmail')) $data['email'] = $entity->getEmail();
                if (method_exists($entity, 'getName')) $data['name'] = $entity->getName();
                if (method_exists($entity, 'getNombre')) $data['nombre'] = $entity->getNombre();
                if (method_exists($entity, 'getTitle')) $data['title'] = $entity->getTitle();
                if (method_exists($entity, 'getContent')) $data['content'] = $entity->getContent();
            }
            
            // Custom logic for Conversation to capture participants
            if ($entity instanceof \App\Entity\Conversation) {
                $participantEmails = [];
                foreach ($entity->getParticipants() as $participant) {
                    $pUser = $participant->getUser();
                    if ($pUser) {
                        $participantEmails[] = $pUser->getEmail();
                    }
                }
                $data['participants'] = $participantEmails;
            }
            
            $log->setDetails($data);
        }

        $entityManager->persist($log);
        $entityManager->flush($log);
    }
}
