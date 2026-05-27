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

        // Always exclude logically deleted events
        $qb->andWhere('e.deletedAt IS NULL');

        // Aggregate condition: (e.isCompanyWide = true) OR (e.owner = :user AND e.isCompanyWide = false)
        $qb->andWhere(
            $qb->expr()->orX(
                'e.isCompanyWide = true',
                'e.owner = :user'
            )
        )->setParameter('user', $user);

        // Date Range Filters
        if (isset($criteria['start']) && $criteria['start'] !== '') {
            $qb->andWhere('e.endAt >= :start')
               ->setParameter('start', new \DateTime($criteria['start']));
        }
        if (isset($criteria['end']) && $criteria['end'] !== '') {
            $qb->andWhere('e.startAt <= :end')
               ->setParameter('end', new \DateTime($criteria['end']));
        }

        // Tag Filter
        if (isset($criteria['tag']) && trim($criteria['tag']) !== '') {
            $qb->andWhere('e.tags LIKE :tag')
               ->setParameter('tag', '%"' . trim($criteria['tag']) . '"%');
        }

        // Type Filter (personal vs company)
        if (isset($criteria['type']) && $criteria['type'] !== '') {
            if ($criteria['type'] === 'personal') {
                $qb->andWhere('e.isCompanyWide = false');
            } elseif ($criteria['type'] === 'company') {
                $qb->andWhere('e.isCompanyWide = true');
            }
        }

        // Order chronologically by start date
        $qb->orderBy('e.startAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function findUpcomingReminders(User $user, \DateTime $now, int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('e');

        // Always exclude logically deleted events
        $qb->andWhere('e.deletedAt IS NULL');

        // Aggregate condition: (e.isCompanyWide = true) OR (e.owner = :user AND e.isCompanyWide = false)
        $qb->andWhere(
            $qb->expr()->orX(
                'e.isCompanyWide = true',
                'e.owner = :user'
            )
        )->setParameter('user', $user);

        // Reminder condition:
        // 1. reminderAt is not null
        // 2. reminderAt <= NOW
        // 3. The event has not finished yet (endAt >= NOW)
        $qb->andWhere('e.reminderAt IS NOT NULL')
           ->andWhere('e.reminderAt <= :now')
           ->andWhere('e.endAt >= :now')
           ->setParameter('now', $now);

        $qb->orderBy('e.startAt', 'ASC')
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }
}
