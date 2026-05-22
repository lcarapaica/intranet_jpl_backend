<?php

namespace App\Repository;

use App\Entity\Conversation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Conversation|null find($id, $lockMode = null, $lockVersion = null)
 * @method Conversation|null findOneBy(array $criteria, array $orderBy = null)
 * @method Conversation[]    findAll()
 * @method Conversation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Finds a private conversation between two specific users.
     */
    public function findPrivateConversationBetweenUsers(int $userAId, int $userBId): ?Conversation
    {
        $qb = $this->createQueryBuilder('c');
        $qb->select('c')
            ->innerJoin('c.participants', 'p1')
            ->innerJoin('c.participants', 'p2')
            ->where('c.type = :type')
            ->andWhere('p1.user = :userA')
            ->andWhere('p2.user = :userB')
            ->setParameter('type', 'private')
            ->setParameter('userA', $userAId)
            ->setParameter('userB', $userBId)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Lists all conversations for a specific user, sorted by updatedAt.
     */
    public function findAllForUser(int $userId): array
    {
        $qb = $this->createQueryBuilder('c');
        $qb->innerJoin('c.participants', 'p')
            ->where('p.user = :userId')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('userId', $userId)
            ->orderBy('c.updatedAt', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
