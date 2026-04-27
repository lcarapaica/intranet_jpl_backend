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

    public function searchAndPaginate($term, $page = 1, $limit = 25)
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.deletedAt IS NULL'); // Ignore Soft Deleted rows

        // Incremental Search, perhaps too inclusive, does NOT check for ID
        if ($term !== null && $term !== '') {
            $qb->andWhere('p.nombre LIKE :term OR p.color LIKE :term OR p.categoria LIKE :term OR p.marca LIKE :term OR p.modelo LIKE :term OR p.serial LIKE :term OR p.locacion LIKE :term OR p.caracteristicas LIKE :term')
                ->setParameter('term', '%' . $term . '%');
        }

        $qb->orderBy('p.id', 'DESC');

        // Pagination
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb);
        $totalItems = count($paginator);
        $totalPages = ceil($totalItems / $limit);

        //defines what categories the search returns
        $data = [];
        foreach ($paginator as $product) {
            $data[] = [
                'id' => $product->getId(),
                'nombre' => $product->getNombre(),
                'categoria' => $product->getCategoria(),
                'marca' => $product->getMarca(),
                'modelo' => $product->getModelo(),
                'caracteristicas' => $product->getCaracteristicas(),
                'color' => $product->getColor(),
                'serial' => $product->getSerial(),
                'condicion' => $product->getCondicion(),
                'locacion' => $product->getLocacion(),
            ];
        }

        //returns the data and additional pagination info
        return [
            'data' => $data,
            'meta' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => (int) $page,
                'limit' => (int) $limit
            ]
        ];
    }
}
