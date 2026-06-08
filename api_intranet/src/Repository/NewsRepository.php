<?php

namespace App\Repository;

use App\Entity\News;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Tools\Pagination\Paginator;

class NewsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, News::class);
    }

    public function searchAndPaginate(array $criteria): array
    {
        // Set fallback default values
        $criteria = array_merge([
            'search'   => '',
            'page'     => 1,
            'limit'    => 25,
            'category' => null,
            'active'   => true,
            'sort'     => 'postedAt',
            'order'    => 'DESC',
        ], $criteria);

        $criteria['search'] = trim($criteria['search']);
        extract($criteria);

        $qb = $this->createQueryBuilder('n')
            ->leftJoin('n.author', 'a')
            ->addSelect('a');

        // 1. Soft-delete activity filter (only show active records by default)
        if ($active === true) {
            $qb->andWhere('n.deletedAt IS NULL');
        } elseif ($active === false) {
            $qb->andWhere('n.deletedAt IS NOT NULL');
        }

        // 2. Category tag filter
        if ($category !== null && trim($category) !== '') {
            $qb->andWhere('n.category = :category')
               ->setParameter('category', trim($category));
        }

        // 3. Multi-word search
        if ($search !== '') {
            $words = explode(' ', $search);
            $i = 0;
            foreach ($words as $word) {
                $word = trim($word);
                if ($word === '') continue;

                $paramName = 'term_' . $i;
                $qb->andWhere(
                    $qb->expr()->orX(
                        "n.title LIKE :$paramName",
                        "n.body LIKE :$paramName",
                        "n.category LIKE :$paramName",
                        "a.name LIKE :$paramName",
                        "a.surname LIKE :$paramName"
                    )
                )->setParameter($paramName, '%' . $word . '%');
                $i++;
            }
        }

        // 4. Dynamic Sorting
        $allowedFields = ['id', 'postedAt', 'updatedAt'];
        if (!in_array($sort, $allowedFields)) {
            $sort = 'postedAt';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $qb->orderBy('n.' . $sort, $order);

        // Pagination: Get total items count first using a cloned query builder
        $countQb = clone $qb;
        $countQb->select('COUNT(n.id)');
        $totalItems = (int) $countQb->getQuery()->getSingleScalarResult();
        $totalPages = ceil($totalItems / $limit);

        // Fetch paginated news
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
           ->setMaxResults($limit);
        $newsItems = $qb->getQuery()->getResult();

        $data = [];
        foreach ($newsItems as $news) {
            $newsArray = [
                'id'        => $news->getId(),
                'title'     => $news->getTitle(),
                'body'      => $news->getBody(),
                'category'  => $news->getCategory(),
                'postedAt'  => $news->getPostedAt() ? $news->getPostedAt()->format('Y-m-d H:i:s') : null,
                'updatedAt' => $news->getUpdatedAt() ? $news->getUpdatedAt()->format('Y-m-d H:i:s') : null,
                'isActive'  => $news->isActive(),
                'author'    => [
                    'id'    => $news->getAuthor()->getId(),
                    'name'  => $news->getAuthor()->getDisplayName()
                ]
            ];

            if (isset($criteria['show_deleted_at']) && $criteria['show_deleted_at'] === true) {
                $newsArray['deletedAt'] = $news->getDeletedAt() ? $news->getDeletedAt()->format('Y-m-d H:i:s') : null;
            }

            $data[] = $newsArray;
        }

        return [
            'data' => $data,
            'meta' => [
                'total_items'  => $totalItems,
                'total_pages'  => $totalPages,
                'current_page' => (int) $page,
                'limit'        => (int) $limit
            ]
        ];
    }
}
