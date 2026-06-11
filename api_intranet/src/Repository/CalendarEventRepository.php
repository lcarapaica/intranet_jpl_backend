<?php

namespace App\Repository;

use App\Entity\CalendarEvent;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    public function findUserEventsFeed(User $user, array $criteria): array
    {
        $qb = $this->createQueryBuilder('e');

        // Filter logically deleted events
        $includeDeleted = $criteria['include_deleted'] ?? false;
        $onlyDeleted = $criteria['only_deleted'] ?? false;

        if ($includeDeleted) {
            if ($onlyDeleted) {
                $qb->andWhere('e.deletedAt IS NOT NULL');
            }
        } else {
            $qb->andWhere('e.deletedAt IS NULL');
        }

        // (e.isCompanyWide = true) OR (e.owner = :user) OR (:user MEMBER OF e.participants)
        $qb->andWhere(
            $qb->expr()->orX(
                'e.isCompanyWide = true',
                'e.owner = :user',
                ':user MEMBER OF e.participants'
            )
        )->setParameter('user', $user);

        // Date Range Filters
        if (isset($criteria['start']) && $criteria['start'] !== '') {
            $qb->andWhere('e.date >= :start')
                ->setParameter('start', new \DateTime($criteria['start']));
        }
        if (isset($criteria['end']) && $criteria['end'] !== '') {
            $qb->andWhere('e.date <= :end')
                ->setParameter('end', new \DateTime($criteria['end']));
        }

        // Tag Filter
        if (isset($criteria['tag']) && trim($criteria['tag']) !== '') {
            $qb->andWhere('e.tags LIKE :tag')
                ->setParameter('tag', '%"' . trim($criteria['tag']) . '"%');
        }

        // Type Filter (personal vs company vs participating)
        if (isset($criteria['type']) && $criteria['type'] !== '') {
            if ($criteria['type'] === 'personal') {
                $qb->andWhere('e.isCompanyWide = false');
            } elseif ($criteria['type'] === 'company') {
                $qb->andWhere('e.isCompanyWide = true');
            } elseif ($criteria['type'] === 'participating') {
                $qb->andWhere(':user MEMBER OF e.participants');
            }
        }

        // Order chronologically by date and start hour
        $qb->orderBy('e.date', 'ASC')
            ->addOrderBy('e.startAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function findUpcomingReminders(User $user, \DateTime $now, int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('e');

        // Always exclude logically deleted events
        $qb->andWhere('e.deletedAt IS NULL');

        // (e.isCompanyWide = true) OR (e.owner = :user) OR (:user MEMBER OF e.participants)
        $qb->andWhere(
            $qb->expr()->orX(
                'e.isCompanyWide = true',
                'e.owner = :user',
                ':user MEMBER OF e.participants'
            )
        )->setParameter('user', $user);

        // Reminder condition:
        // 1. reminderAt is not null
        // 2. reminderAt <= NOW
        // 3. The event has not finished yet (endAt >= NOW)
        $qb->andWhere('e.reminderAt IS NOT NULL')
            ->andWhere('e.reminderAt <= :now')
            ->andWhere(
                $qb->expr()->orX(
                    'e.endAt >= :now',
                    $qb->expr()->andX(
                        'e.endAt IS NULL',
                        'e.date >= :today'
                    )
                )
            )
            ->setParameter('now', $now)
            ->setParameter('today', $now->format('Y-m-d'));

        $qb->orderBy('e.date', 'ASC')
            ->addOrderBy('e.startAt', 'ASC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }
}
