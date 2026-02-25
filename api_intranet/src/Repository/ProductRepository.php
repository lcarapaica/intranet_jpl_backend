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
            ->where('p.deletedAt IS NULL'); // Ignorar los eliminados lógicamente

        // Búsqueda Incremental (Filtro por todos los campos)
        if (!empty($term)) {
            $qb->andWhere('p.nombre LIKE :term OR p.categoria LIKE :term OR p.marca LIKE :term OR p.modelo LIKE :term OR p.serial LIKE :term OR p.locacion LIKE :term OR p.caracteristicas LIKE :term')
               ->setParameter('term', '%' . $term . '%');
        }

        $qb->orderBy('p.id', 'DESC');

        // Paginación
        $offset = ($page - 1) * $limit;
        $qb->setFirstResult($offset)
           ->setMaxResults($limit);

        $paginator = new Paginator($qb);
        $totalItems = count($paginator);
        $totalPages = ceil($totalItems / $limit);

        $data = [];
        foreach ($paginator as $product) {
            $data[] = [
                'id' => $product->getId(),
                'nombre' => $product->getNombre(),
                'categoria' => $product->getCategoria(),
                'marca' => $product->getMarca(),
                'modelo' => $product->getModelo(),
                'serial' => $product->getSerial(),
                'condicion' => $product->getCondicion(), // Arreglo ej: ["Nuevo"]
                'locacion' => $product->getLocacion(),
            ];
        }

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
