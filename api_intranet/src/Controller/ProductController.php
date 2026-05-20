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
 * @Route("/api/products")
 */
class ProductController extends AbstractController
{
    /**
     * @Route("", methods={"GET"})
     * @OA\Get(
     * summary="Lists products",
     * tags={"Productos"},
     * @OA\Parameter(name="search", in="query", description="Search String", @OA\Schema(type="string")),
     * @OA\Parameter(name="limit", in="query", description="Page limit (10, 25, 50, 100)", @OA\Schema(type="integer", default=25)),
     * @OA\Parameter(name="page", in="query", description="Page Number", @OA\Schema(type="integer", default=1)),
     * @OA\Parameter(name="empresa", in="query", description="Filter by exact Empresa name", @OA\Schema(type="string")),
     * @OA\Parameter(name="active", in="query", description="Filter by active status (true/false). Only admins/logistics can see false.", @OA\Schema(type="string")),
     * @OA\Parameter(name="sort", in="query", description="Sort by field (nombre, categoria, marca, modelo, empresa, cantidad, etc.)", @OA\Schema(type="string", default="id")),
     * @OA\Parameter(name="order", in="query", description="Sort order (ASC, DESC)", @OA\Schema(type="string", default="DESC")),
     * @OA\Response(response=200, description="List of products")
     * )
     */
    //Manages the search query
    public function index(Request $request, ProductRepository $repository): JsonResponse
    {
        $search  = $request->query->get('search', '');
        $limit   = $request->query->getInt('limit', 25);
        $page    = $request->query->getInt('page', 1);
        $empresa = $request->query->get('empresa');
        $sort    = $request->query->get('sort', 'id');
        $order   = $request->query->get('order', 'DESC');

        if (!in_array($limit, [10, 25, 50, 100])) {
            $limit = 25;
        }

        $activeParam = $request->query->get('active');
        $active = true; // Defaults to active so pagination limits aren't affected by mixed statuses
        
        if ($activeParam !== null && $activeParam !== '') {
            $parsedActive = filter_var($activeParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsedActive !== null) {
                $active = $parsedActive;
            }
        }

        // Only ROLE_ADMIN or ROLE_LOGISTICS can see inactive products
        $hasAccessToInactive = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_LOGISTICS');
        if (!$hasAccessToInactive) {
            $active = true;
        }

        $result = $repository->searchAndPaginate($search, $page, $limit, $empresa, $active, $sort, $order);

        return $this->json($result);
    }

    /**
     * @Route("/{id}", methods={"GET"})
     * @OA\Get(
     *     path="/api/products/{id}",
     *     summary="Get details of a single product",
     *     tags={"Productos"},
     *     @OA\Parameter(name="id", in="path", description="Product ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Product details",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="nombre", type="string", example="Laptop ThinkPad"),
     *             @OA\Property(property="categoria", type="string", example="Computación"),
     *             @OA\Property(property="marca", type="string", example="Lenovo"),
     *             @OA\Property(property="modelo", type="string", example="T14 Gen 2"),
     *             @OA\Property(property="caracteristicas", type="string", example="16GB RAM, 512GB SSD"),
     *             @OA\Property(property="color", type="string", example="Negro"),
     *             @OA\Property(property="serial", type="string", nullable=true, example=null),
     *             @OA\Property(property="condicion", type="string", example="Nuevo"),
     *             @OA\Property(property="locacion", type="string", example="Almacén Principal"),
     *             @OA\Property(property="cantidad", type="integer", example=10),
     *             @OA\Property(property="empresa", type="string", example="JPL"),
     *             @OA\Property(property="registeredAt", type="string", format="date", example="2026-02-15"),
     *             @OA\Property(property="isActive", type="boolean", example=true),
     *             @OA\Property(property="deletedAt", type="string", nullable=true, example=null)
     *         )
     *     ),
     *     @OA\Response(response=404, description="Product not found")
     * )
     */
    public function show(int $id, ProductRepository $repository): JsonResponse
    {
        $product = $repository->find($id);

        if (!$product) {
            return $this->json(['error' => 'Producto no encontrado'], 404);
        }

        // Hide deleted products from non-admins
        if (!$product->isActive() && !$this->isGranted('ROLE_LOGISTICS')) {
            return $this->json(['error' => 'Producto no encontrado'], 404);
        }


        $productData = [
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
            'cantidad' => $product->getCantidad(),
            'empresa' => $product->getEmpresa(),
            'registeredAt' => $product->getRegisteredAt() ? $product->getRegisteredAt()->format('Y-m-d') : null,
            'isActive' => $product->isActive(),
        ];

        if ($this->isGranted('ROLE_LOGISTICS')) {
            $productData['deletedAt'] = $product->getDeletedAt() ? $product->getDeletedAt()->format('Y-m-d H:i:s') : null;
        }

        return $this->json($productData);
    }

    /**
     * @Route("", methods={"POST"})
     * @OA\Post(
     * summary="Create a new product",
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
     * @OA\Property(property="locacion", type="string", example="Almacén Principal"),
     * @OA\Property(property="cantidad", type="integer", example=10)
     * )
     * ),
     * @OA\Response(response=201, description="Product Created")
     * )
     */
    //Creates a new Product
    public function create(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        if (!$this->isGranted('ROLE_LOGISTICS')) {
            return $this->json(['error' => 'No tienes permisos para crear productos.'], 403);
        }
        $content = $request->getContent();
        $data = json_decode($content, true);

        if ($content && $data === null) {
            return $this->json(['error' => 'Formato JSON inválido'], 400);
        }

        $data = $data ?: [];
        $product = new Product();


        //sets fields to values
        $product->setNombre($data['nombre'] ?? '');
        $product->setCategoria($data['categoria'] ?? '');
        $product->setMarca($data['marca'] ?? '');
        $product->setModelo($data['modelo'] ?? '');
        $product->setCaracteristicas($data['caracteristicas'] ?? null);
        $product->setColor($data['color'] ?? null);
        $product->setSerial($data['serial'] ?? null);
        $product->setCondicion($data['condicion'] ?? '');
        $product->setLocacion($data['locacion'] ?? null);
        $product->setCantidad($data['cantidad'] ?? null);
        $product->setEmpresa($data['empresa'] ?? null);
        if (isset($data['registeredAt'])) {
            $product->setRegisteredAt(new \DateTime($data['registeredAt']));
        }


        // El @Assert\Validates each field with the product entity
        $errors = $validator->validate($product);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        $em->persist($product);
        $em->flush();

        return $this->json(['message' => 'Producto creado', 'id' => $product->getId()], 201);
    }

    /**
     * @Route("/{id}", methods={"PUT"})
     * @OA\Put(
     *     summary="Update a product",
     *     tags={"Productos"},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="nombre", type="string"),
     *             @OA\Property(property="categoria", type="string"),
     *             @OA\Property(property="marca", type="string"),
     *             @OA\Property(property="modelo", type="string"),
     *             @OA\Property(property="caracteristicas", type="string"),
     *             @OA\Property(property="color", type="string"),
     *             @OA\Property(property="serial", type="string", nullable=true),
     *             @OA\Property(property="condicion", type="string"),
     *             @OA\Property(property="locacion", type="string"),
     *             @OA\Property(property="cantidad", type="integer"),
     *             @OA\Property(property="empresa", type="string"),
     *             @OA\Property(property="registeredAt", type="string", format="date"),
     *             @OA\Property(property="deletedAt", type="string", nullable=true, example=null)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Product Updated")
     * )
     */
    //Updates a product
    public function update(int $id, Request $request, EntityManagerInterface $em, ProductRepository $repository, ValidatorInterface $validator): JsonResponse
    {
        if (!$this->isGranted('ROLE_LOGISTICS')) {
            return $this->json(['error' => 'No tienes permisos para editar este producto.'], 403);
        }
        $product = $repository->find($id);

        //checks if a valid id was entered 
        if (!$product) {
            return $this->json(['error' => 'Producto no encontrado'], 404);
        }

        $content = $request->getContent();
        $data = json_decode($content, true);

        if ($content && $data === null) {
            return $this->json(['error' => 'Formato JSON inválido'], 400);
        }

        $data = $data ?: [];

        // sets fields to new values if they are present in the request
        if (array_key_exists('nombre', $data)) $product->setNombre($data['nombre']);
        if (array_key_exists('categoria', $data)) $product->setCategoria($data['categoria']);
        if (array_key_exists('marca', $data)) $product->setMarca($data['marca']);
        if (array_key_exists('modelo', $data)) $product->setModelo($data['modelo']);
        if (array_key_exists('caracteristicas', $data)) $product->setCaracteristicas($data['caracteristicas']);
        if (array_key_exists('color', $data)) $product->setColor($data['color']);
        if (array_key_exists('serial', $data)) $product->setSerial($data['serial']);
        if (array_key_exists('locacion', $data)) $product->setLocacion($data['locacion']);
        if (array_key_exists('condicion', $data)) $product->setCondicion($data['condicion']);
        if (array_key_exists('cantidad', $data)) $product->setCantidad($data['cantidad']);
        if (array_key_exists('empresa', $data)) $product->setEmpresa($data['empresa']);
        if (array_key_exists('registeredAt', $data)) {
            $product->setRegisteredAt($data['registeredAt'] ? new \DateTime($data['registeredAt']) : null);
        }

        //El @Assert\Validates each field with the product entity
        $errors = $validator->validate($product);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        $em->persist($product);
        $em->flush();

        return $this->json(['message' => 'Producto actualizado correctamente']);
    }

    /**
     * @Route("/{id}", methods={"DELETE"})
     * @OA\Delete(
     * summary="Soft Delete a Product",
     * tags={"Productos"},
     * @OA\Response(response=200, description="Product Eliminated")
     * )
     */
    //Deletes a product
    public function delete(int $id, EntityManagerInterface $em, ProductRepository $repository): JsonResponse
    {
        if (!$this->isGranted('ROLE_LOGISTICS')) {
            return $this->json(['error' => 'No tienes permisos para eliminar este producto.'], 403);
        }
        $product = $repository->find($id);

        //checks if an id was entered or item was deleted
        if (!$product || $product->getDeletedAt() !== null) {
            return $this->json(['error' => 'Producto no encontrado'], 404);
        }

        $product->setDeletedAt(new \DateTime());
        $em->flush();

        return $this->json(['message' => 'Producto eliminado lógicamente']);
    }

    /**
     * @Route("/bulk", methods={"POST"})
     * @OA\Post(
     *     path="/api/products/bulk",
     *     summary="Bulk create products from a JSON list",
     *     tags={"Productos"},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="nombre", type="string", example="Laptop ThinkPad"),
     *                 @OA\Property(property="categoria", type="string", example="Computación"),
     *                 @OA\Property(property="marca", type="string", example="Lenovo"),
     *                 @OA\Property(property="modelo", type="string", example="T14 Gen 2"),
     *                 @OA\Property(property="caracteristicas", type="string", example="16GB RAM, 512GB SSD"),
     *                 @OA\Property(property="color", type="string", example="Negro"),
     *                 @OA\Property(property="serial", type="string", nullable=true, example="SN123456"),
     *                 @OA\Property(property="condicion", type="string", example="Nuevo"),
     *                 @OA\Property(property="locacion", type="string", example="Almacén Principal"),
     *                 @OA\Property(property="cantidad", type="integer", example=10),
     *                 @OA\Property(property="empresa", type="string", example="JPL"),
     *                 @OA\Property(property="registeredAt", type="string", format="date", example="2026-05-14")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Products Created Successfully"),
     *     @OA\Response(response=400, description="Validation Errors or Invalid JSON")
     * )
     */
    public function bulkCreate(Request $request, EntityManagerInterface $em, ValidatorInterface $validator, \App\Service\AuditLogger $auditLogger): JsonResponse
    {
        if (!$this->isGranted('ROLE_LOGISTICS')) {
            return $this->json(['error' => 'No tienes permisos para realizar cargas masivas.'], 403);
        }

        $content = $request->getContent();
        $data = json_decode($content, true);

        if ($data === null || !is_array($data)) {
            return $this->json(['error' => 'Formato JSON inválido. Se espera una lista de productos.'], 400);
        }

        // Mute individual logs to avoid saturating MySQL
        $auditLogger->mute();

        $batchSize = 20;
        $allErrors = [];
        $productsToSave = [];
        $seenSerials = [];

        foreach ($data as $index => $item) {
            $product = new Product();

            // Asignación con valores por defecto "N/A" para campos obligatorios si vienen vacíos
            $product->setNombre(!empty($item['nombre']) ? $item['nombre'] : 'N/A');
            $product->setCategoria(!empty($item['categoria']) ? $item['categoria'] : 'N/A');
            $product->setMarca(!empty($item['marca']) ? $item['marca'] : 'N/A');
            $product->setModelo(!empty($item['modelo']) ? $item['modelo'] : 'N/A');

            $product->setCaracteristicas($item['caracteristicas'] ?? null);
            $product->setColor($item['color'] ?? null);
            $product->setSerial($item['serial'] ?? null);

            $product->setCondicion(!empty($item['condicion']) ? $item['condicion'] : 'N/A');
            $product->setLocacion(!empty($item['locacion']) ? $item['locacion'] : 'N/A');
            $product->setCantidad(isset($item['cantidad']) ? (int)$item['cantidad'] : 0);
            $product->setEmpresa(!empty($item['empresa']) ? $item['empresa'] : 'N/A');

            if (!empty($item['registeredAt'])) {
                try {
                    $product->setRegisteredAt(new \DateTime($item['registeredAt']));
                } catch (\Exception $e) {
                    $allErrors[] = [
                        'fila' => $index + 1,
                        'error' => "Formato de fecha inválido para 'registeredAt'.",
                        'producto' => $item['nombre'] ?? 'Sin nombre'
                    ];
                }
            }

            $errors = $validator->validate($product);
            if (count($errors) > 0) {
                $msg = [];
                foreach ($errors as $error) {
                    $msg[] = $error->getMessage();
                }
                $allErrors[] = [
                    'fila' => $index + 1,
                    'producto' => $item['nombre'] ?? 'Sin nombre',
                    'errores' => $msg
                ];
            } else {
                $productsToSave[] = $product;
            }
        }

        if (count($allErrors) > 0) {
            $auditLogger->unmute();
            return $this->json([
                'error' => 'Se encontraron errores de validación. No se procesó la carga.',
                'total_filas' => count($data),
                'errores_encontrados' => count($allErrors),
                'detalles' => $allErrors
            ], 400);
        }

        // Batch processing
        try {
            foreach ($productsToSave as $i => $product) {
                $em->persist($product);
                if (($i % $batchSize) === 0) {
                    $em->flush();
                }
            }
            $em->flush();

            // Log the bulk summary
            $auditLogger->unmute();
            $auditLogger->logBulk(Product::class, count($productsToSave), $data);
        } catch (\Exception $e) {
            $auditLogger->unmute();
            return $this->json(['error' => 'Error al guardar los productos: ' . $e->getMessage()], 500);
        }

        return $this->json([
            'message' => count($productsToSave) . ' productos cargados exitosamente.',
            'count' => count($productsToSave)
        ], 201);
    }

    /**
     * @Route("/{id}/toggle-active", methods={"POST"})
     * @OA\Post(
     *     path="/api/products/{id}/toggle-active",
     *     summary="Toggle product active/inactive status",
     *     tags={"Productos"},
     *     @OA\Parameter(name="id", in="path", description="Product ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Status toggled"),
     *     @OA\Response(response=404, description="Product not found")
     * )
     */
    public function toggleActive(int $id, ProductRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        $product = $repository->find($id);

        if (!$product) {
            return $this->json(['error' => 'Producto no encontrado'], 404);
        }

        // Restrict this action to admins only
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'No tienes permisos para cambiar el estado de este producto.'], 403);
        }

        if ($product->isActive()) {
            $product->setDeletedAt(new \DateTime());
        } else {
            $product->setDeletedAt(null);
        }

        $em->flush();

        return $this->json([
            'message' => $product->isActive() ? 'Producto activado' : 'Producto desactivado',
            'isActive' => $product->isActive()
        ]);
    }
}
