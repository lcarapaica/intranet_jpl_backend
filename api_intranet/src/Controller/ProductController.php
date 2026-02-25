<?php

namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use OpenApi\Annotations as OA;

/**
 * @Route("/api/productos")
 */
class ProductController extends AbstractController
{
    /**
     * @Route("", methods={"GET"})
     * @OA\Get(
     * summary="Listar productos (Paginación y Búsqueda)",
     * tags={"Productos"},
     * @OA\Parameter(name="search", in="query", description="Término de búsqueda incremental", @OA\Schema(type="string")),
     * @OA\Parameter(name="limit", in="query", description="Límite (25, 50, 100)", @OA\Schema(type="integer", default=25)),
     * @OA\Parameter(name="page", in="query", description="Número de página", @OA\Schema(type="integer", default=1)),
     * @OA\Response(response=200, description="Lista de productos")
     * )
     */
    public function index(Request $request, ProductRepository $repository): JsonResponse
    {
        $search = $request->query->get('search', '');
        $limit = $request->query->getInt('limit', 25);
        $page = $request->query->getInt('page', 1);

        if (!in_array($limit, [25, 50, 100])) {
            $limit = 25;
        }

        $result = $repository->searchAndPaginate($search, $page, $limit);
        return $this->json($result);
    }

    /**
     * @Route("", methods={"POST"})
     * @OA\Post(
     * summary="Crear un nuevo producto",
     * tags={"Productos"},
     * @OA\RequestBody(
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="nombre", type="string", example="Laptop ThinkPad"),
     * @OA\Property(property="categoria", type="string", example="Computación"),
     * @OA\Property(property="marca", type="string", example="Lenovo"),
     * @OA\Property(property="modelo", type="string", example="T14 Gen 2"),
     * @OA\Property(property="caracteristicas", type="string", example="16GB RAM, 512GB SSD"),
     * @OA\Property(property="color", type="string", example="Negro"),
     * @OA\Property(property="serial", type="string", nullable=true, example=null),
     * @OA\Property(property="condicion", type="string", example="Nuevo"),
     * @OA\Property(property="locacion", type="string", example="Almacén Principal")
     * )
     * ),
     * @OA\Response(response=201, description="Producto creado")
     * )
     */
    public function create(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $product = new Product();

        $product->setNombre($data['nombre'] ?? '');
        $product->setCategoria($data['categoria'] ?? '');
        $product->setMarca($data['marca'] ?? '');
        $product->setModelo($data['modelo'] ?? '');
        $product->setCaracteristicas($data['caracteristicas'] ?? null);
        $product->setColor($data['color'] ?? null);
        $product->setSerial($data['serial'] ?? null);
        $product->setCondicion($data['condicion'] ?? '');
        $product->setLocacion($data['locacion'] ?? null);
        
        // El @Assert\Choice de la entidad se encarga de validar "Nuevo" o "Usado" automáticamente
        $errors = $validator->validate($product);
        if (count($errors) > 0) {
            return $this->json(['error' => (string) $errors], 400);
        }

        $em->persist($product);
        $em->flush();

        return $this->json(['message' => 'Producto creado', 'id' => $product->getId()], 201);
    }

    /**
     * @Route("/{id}", methods={"PUT"})
     * @OA\Put(
     * summary="Actualizar un producto",
     * tags={"Productos"},
     * @OA\Response(response=200, description="Producto actualizado")
     * )
     */
    public function update(int $id, Request $request, EntityManagerInterface $em, ProductRepository $repository, ValidatorInterface $validator): JsonResponse
    {
        $product = $repository->find($id);

        if (!$product || $product->getDeletedAt() !== null) {
            return $this->json(['error' => 'Producto no encontrado'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['nombre'])) $product->setNombre($data['nombre']);
        if (isset($data['categoria'])) $product->setCategoria($data['categoria']);
        if (isset($data['marca'])) $product->setMarca($data['marca']);
        if (isset($data['modelo'])) $product->setModelo($data['modelo']);
        if (isset($data['caracteristicas'])) $product->setCaracteristicas($data['caracteristicas']);
        if (isset($data['color'])) $product->setColor($data['color']);
        if (array_key_exists('serial', $data)) $product->setSerial($data['serial']); 
        if (isset($data['locacion'])) $product->setLocacion($data['locacion']);
        if (isset($data['condicion'])) $product->setCondicion($data['condicion']);

        $errors = $validator->validate($product);
        if (count($errors) > 0) {
            return $this->json(['error' => (string) $errors], 400);
        }

        $em->flush();

        return $this->json(['message' => 'Producto actualizado correctamente']);
    }

    /**
     * @Route("/{id}", methods={"DELETE"})
     * @OA\Delete(
     * summary="Eliminar un producto (Eliminado Lógico)",
     * tags={"Productos"},
     * @OA\Response(response=200, description="Producto eliminado")
     * )
     */
    public function delete(int $id, EntityManagerInterface $em, ProductRepository $repository): JsonResponse
    {
        $product = $repository->find($id);

        if (!$product || $product->getDeletedAt() !== null) {
            return $this->json(['error' => 'Producto no encontrado'], 404);
        }

        $product->setDeletedAt(new \DateTime());
        $em->flush();

        return $this->json(['message' => 'Producto eliminado lógicamente']);
    }
}
