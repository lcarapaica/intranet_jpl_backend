<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * UserRepository
 * 
 * Manages database queries for the User table.
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Automatically updates and rehashes the user password over time when needed.
     */
    public function upgradePassword(UserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newHashedPassword);
        $this->_em->persist($user);
        $this->_em->flush();
    }

    /**
     * Filters, searches, and paginates the users list based on criteria array.
     */
    public function searchAndPaginate(array $criteria): array
    {
        // Set fallback default values for missing keys
        $criteria = array_merge([
            'search'         => '',
            'page'           => 1,
            'limit'          => 25,
            'hasAdminAccess' => false,
            'role'           => null,
            'isActive'       => true,
            'sort'           => 'id',
            'order'          => 'DESC',
        ], $criteria);

        // Prepares array keys as local variables ($search, $page, $limit, etc.)
        $criteria['search'] = trim($criteria['search']);
        extract($criteria);
        
        // Start building the query with alias 'u'
        $qb = $this->createQueryBuilder('u');

        // Activity filter: non-admins are forced to only see active records
        if (!$hasAdminAccess || $isActive === true) {
            $qb->andWhere('u.deletedAt IS NULL');
        } elseif ($isActive === false) {
            $qb->andWhere('u.deletedAt IS NOT NULL');
        }

        // Role category filter
        if ($role !== null && $role !== '') {
            $qb->andWhere('u.roles LIKE :role')
                ->setParameter('role', '%"' . $role . '"%');
        }

        // Multi-word search matching email, name, or surname
        if (!empty($search)) {
            $words = explode(' ', $search);
            $i = 0;
            foreach ($words as $word) {
                $word = trim($word);
                if ($word === '') continue;

                $paramName = 'term_' . $i;
                $qb->andWhere(
                    $qb->expr()->orX(
                        "u.email LIKE :$paramName",
                        "u.name LIKE :$paramName",
                        "u.surname LIKE :$paramName"
                    )
                )->setParameter($paramName, '%' . $word . '%');
                $i++;
            }
        }

        // Dynamic sorting with a safe field whitelist check
        $allowedFields = ['id', 'email', 'name', 'surname'];
        if (!in_array($sort, $allowedFields)) {
            $sort = 'id';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $qb->orderBy('u.' . $sort, $order);

        // Pagination: Get total items count first using a cloned query builder
        $countQb = clone $qb;
        $countQb->select('COUNT(u.id)');
        $totalItems = (int) $countQb->getQuery()->getSingleScalarResult();
        $totalPages = ceil((int)$totalItems / $limit);

        // Fetch paginated users
        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);
        $users = $qb->getQuery()->getResult();

        // Map database objects into a clean associative array
        $data = [];
         foreach ($users as $user) {
             $roles = $user->getRoles();
             $role = count($roles) > 0 ? $roles[0] : 'ROLE_USER';

             // Hide superuser role from non-admins
             if ($role === 'ROLE_SUPER_ADMIN' && !$hasAdminAccess) {
                 $role = 'ROLE_ADMIN';
             }

             $userArray = [
                 'id'       => $user->getId(),
                 'email'    => $user->getEmail(),
                 'name'     => $user->getName(),
                 'surname'  => $user->getSurname(),
                 'role'     => $role,
                 'isActive' => $user->isActive(),
             ];

             // Only add deletedAt field if current visitor has admin privileges
             if ($hasAdminAccess) {
                 $userArray['deletedAt'] = $user->getDeletedAt() ? $user->getDeletedAt()->format('Y-m-d H:i:s') : null;
             }

             $data[] = $userArray;
         }

        // Return structured dataset paired with standard pagination metrics
        return [
            'data' => $data,
            'meta' => [
                'total_items'  => $totalItems,
                'total_pages'  => (int)max(1, $totalPages),
                'current_page' => (int)$page,
                'limit'        => (int)$limit
            ]
        ];
    }
}