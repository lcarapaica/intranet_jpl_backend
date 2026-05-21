<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Tools\Pagination\Paginator;

class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    // Filters, searches, and paginates the products list based on criteria array
    public function searchAndPaginate(array $criteria): array
    {
        // Set fallback default values for missing keys
        $criteria = array_merge([
            'search'             => '',
            'page'               => 1,
            'limit'              => 25,
            'empresa'            => null,
            'active'             => true,
            'sort'               => 'id',
            'order'              => 'DESC',
            'hasLogisticsAccess' => true,
        ], $criteria);

        // Prepares array keys as local variables ($search, $page, $limit, etc.)
        $criteria['search'] = trim($criteria['search']);
        extract($criteria);

        $qb = $this->createQueryBuilder('p');

        // 1. Activity Filter: non-logistics are forced to only see active records
        if (!$hasLogisticsAccess || $active === true) {
            $qb->andWhere('p.deletedAt IS NULL');
        } elseif ($active === false) {
            $qb->andWhere('p.deletedAt IS NOT NULL');
        }

        // 2. Empresa Filter 
        if ($empresa !== null && $empresa !== '') {
            $qb->andWhere('p.empresa = :empresa')
                ->setParameter('empresa', $empresa);
        }

        // 3. Multi-word Incremental Search 
        if ($search !== '') {
            $words = explode(' ', $search);
            $i = 0;
            foreach ($words as $word) {
                $word = trim($word);
                if ($word === '') continue;
                
                $paramName = 'term_' . $i;
                $qb->andWhere(
                    $qb->expr()->orX(
                        "p.nombre LIKE :$paramName",
                        "p.color LIKE :$paramName",
                        "p.categoria LIKE :$paramName",
                        "p.marca LIKE :$paramName",
                        "p.modelo LIKE :$paramName",
                        "p.serial LIKE :$paramName",
                        "p.locacion LIKE :$paramName",
                        "p.caracteristicas LIKE :$paramName",
                        "p.empresa LIKE :$paramName"
                    )
                )->setParameter($paramName, '%' . $word . '%');
                $i++;
            }
        }

        // Dynamic Sorting
        $allowedFields = ['id', 'nombre', 'categoria', 'marca', 'modelo', 'color', 'serial', 'condicion', 'locacion', 'cantidad', 'empresa', 'registeredAt'];
        if (!in_array($sort, $allowedFields)) {
            $sort = 'id';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $qb->orderBy('p.' . $sort, $order);

        // Pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb);
        $totalItems = count($paginator);
        $totalPages = ceil($totalItems / $limit);

        $data = [];
        foreach ($paginator as $product) {
            $productArray = [
                'id'              => $product->getId(),
                'nombre'          => $product->getNombre(),
                'categoria'       => $product->getCategoria(),
                'marca'           => $product->getMarca(),
                'modelo'          => $product->getModelo(),
                'caracteristicas' => $product->getCaracteristicas(),
                'color'           => $product->getColor(),
                'serial'          => $product->getSerial(),
                'condicion'       => $product->getCondicion(),
                'locacion'        => $product->getLocacion(),
                'cantidad'        => $product->getCantidad(),
                'empresa'         => $product->getEmpresa(),
                'registeredAt'    => $product->getRegisteredAt() ? $product->getRegisteredAt()->format('Y-m-d') : null,
                'isActive'        => $product->isActive(),
            ];

            // If deletedAt is present, include it in response
            if ($product->getDeletedAt() !== null) {
                $productArray['deletedAt'] = $product->getDeletedAt()->format('Y-m-d H:i:s');
            } else {
                $productArray['deletedAt'] = null;
            }

            $data[] = $productArray;
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
