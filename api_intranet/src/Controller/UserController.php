<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use OpenApi\Annotations as OA;

/**
 * @Route("/api/users")
 */
class UserController extends AbstractController
{
    /**
     * @Route("", methods={"GET"})
     * @OA\Get(
     *     path="/api/users",
     *     summary="Returns a list of all the users",
     *     tags={"Usuarios"},
     * @OA\Parameter(name="search", in="query", description="Search String", @OA\Schema(type="string")),
     * @OA\Parameter(name="limit", in="query", description="Page limit (10, 25, 50, 100)", @OA\Schema(type="integer", default=25)),
     * @OA\Parameter(name="page", in="query", description="Page Number", @OA\Schema(type="integer", default=1)),
     * @OA\Parameter(name="role", in="query", description="Filter by role name (e.g. ROLE_ADMIN)", @OA\Schema(type="string")),
     * @OA\Parameter(name="active", in="query", description="Filter by active status (true/false). Only admins can see false.", @OA\Schema(type="string")),
     * @OA\Parameter(name="sort", in="query", description="Sort by field (id, email, name, surname)", @OA\Schema(type="string", default="id")),
     * @OA\Parameter(name="order", in="query", description="Sort order (ASC, DESC)", @OA\Schema(type="string", default="DESC")),
     *     @OA\Response(response=200, description="List of users"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function index(Request $request, UserRepository $repository): JsonResponse
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'No tienes permisos para listar a los usuarios.'], 403);
        }

        $search = $request->query->get('search', '');
        $role = $request->query->get('role');
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 25);
        $sort = $request->query->get('sort', 'id');
        $order = $request->query->get('order', 'DESC');

        $activeParam = $request->query->get('active');
        $active = true; // Defaults to active so pagination limits aren't affected by mixed statuses
        
        if ($activeParam !== null && $activeParam !== '') {
            $parsedActive = filter_var($activeParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsedActive !== null) {
                $active = $parsedActive;
            }
        }

        // 2. Validate limit to prevent database stress
        if (!in_array($limit, [10, 25, 50, 100])) {
            $limit = 25;
        }
        // User must be admin to reach here, so they can see all users
        $result = $repository->searchAndPaginate($search, $page, $limit, true, $role, $active, $sort, $order);

        return $this->json($result);
    }

    /**
     * @Route("/{id}", methods={"GET"})
     * @OA\Get(
     *     path="/api/users/{id}",
     *     summary="Get details of a single user",
     *     tags={"Usuarios"},
     *     @OA\Parameter(name="id", in="path", description="User ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="User details",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="surname", type="string"),
     *             @OA\Property(property="roles", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="isActive", type="boolean"),
     *             @OA\Property(property="mustChangePassword", type="boolean", description="Only for admins"),
     *             @OA\Property(property="deletedAt", type="string", format="date-time", description="Only for admins")
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function show(int $id, UserRepository $repository): JsonResponse
    {
        $user = $repository->find($id);

        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        // Hide deleted users from non-admins
        if (!$user->isActive() && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }


        $roles = $user->getRoles();
        $userData = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'surname' => $user->getSurname(),
            'role' => count($roles) > 0 ? $roles[0] : 'ROLE_USER',
            'isActive' => $user->isActive(),
        ];

        if ($this->isGranted('ROLE_ADMIN')) {
            $userData['mustChangePassword'] = $user->getMustChangePassword();
            $userData['deletedAt'] = $user->getDeletedAt() ? $user->getDeletedAt()->format('Y-m-d H:i:s') : null;
        }

        return $this->json($userData);
    }

    /**
     * @Route("/{id}", methods={"PUT"})
     * @OA\Put(
     *     path="/api/users/{id}",
     *     summary="Updates the credentials or roles of an user",
     *     tags={"Usuarios"},
     *     @OA\Parameter(name="id", in="path", description="User ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="email", type="string", example="user@example.com"),
     *             @OA\Property(property="name", type="string", example="John"),
     *             @OA\Property(property="surname", type="string", example="Doe"),
     *             @OA\Property(property="roles", type="array", @OA\Items(type="string"), example={"ROLE_ADMIN"}),
     *             @OA\Property(property="password", type="string", example="newpassword123")
     *         )
     *     ),
     *     @OA\Response(response=200, description="User updated successfully"),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=400, description="Validation error")
     * )
     */
    public function update(int $id, Request $request, UserRepository $repository, EntityManagerInterface $em, UserPasswordEncoderInterface $encoder, ValidatorInterface $validator): JsonResponse
    {
        $user = $repository->find($id);
        // If the user doesn't exist, throw error
        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        // Checks if the user is able to edit the target user, if not blocks access
        $this->denyAccessUnlessGranted('USER_EDIT', $user);
        $content = $request->getContent();
        $data = json_decode($content, true);

        if ($content && $data === null) {
            return $this->json(['error' => 'Formato JSON inválido'], 400);
        }

        $data = $data ?: [];

        // Maps array into DTO
        $dto = new \App\Dto\UserUpdateDto();
        $dto->email = $data['email'] ?? null;
        $dto->name = $data['name'] ?? null;
        $dto->surname = $data['surname'] ?? null;
        $dto->roles = $data['roles'] ?? null;
        $dto->password = $data['password'] ?? null;

        // Track which fields were actually provided in the JSON body
        $providedFields = array_keys($data);

        // Validates data
        $errors = $validator->validate($dto);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        // Checks if user has permission to edit an user role
        $canEditRoles = $this->isGranted('USER_EDIT_ROLES', $user);
        if ($dto->roles !== null && !$canEditRoles) {
            return $this->json([
                'error' => 'No tienes permisos para modificar los roles de este usuario.'
            ], 403);
        }

        // Check specifically for Super Admin promotion escalation
        $isTryingToGrantSuper = $dto->roles && in_array('ROLE_SUPER_ADMIN', $dto->roles);
        if ($isTryingToGrantSuper && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->json([
                'error' => 'No tienes permisos para modificar los roles de este usuario o asignar el rango solicitado.'
            ], 403);
        }

        $canChangeRoles = $canEditRoles;

        //updates DB with values
        $authenticatedUser = $this->getUser();
        $isSelfUpdate = $authenticatedUser instanceof \App\Entity\User && $authenticatedUser->getId() === $user->getId();

        // Prevent Super Admins from demoting themselves and locking the database
        if ($isSelfUpdate && $dto->roles !== null && in_array('ROLE_SUPER_ADMIN', $user->getRoles()) && !in_array('ROLE_SUPER_ADMIN', $dto->roles)) {
            return $this->json([
                'error' => 'Por seguridad, no puedes remover tus propios permisos de Super Administrador.'
            ], 403);
        }

        try {
            $dto->updateEntity($user, $encoder, $canChangeRoles, $isSelfUpdate, $providedFields);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
        $em->flush();

        return $this->json(['message' => 'Usuario actualizado correctamente']);
    }

    /**
     * @Route("/{id}", methods={"DELETE"})
     * @OA\Delete(
     *     path="/api/users/{id}",
     *     summary="Delete a user",
     *     tags={"Usuarios"},
     *     @OA\Parameter(name="id", in="path", description="User ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="User deleted successfully"),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function delete(int $id, UserRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        $user = $repository->find($id);
        // If the user doesn't exist, throw error
        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        // Authorization check using Voter
        $this->denyAccessUnlessGranted('USER_DELETE', $user);

        // Prevent self-deletion
        if ($this->getUser() && $this->getUser()->getId() === $user->getId()) {
            return $this->json(['error' => 'No puedes eliminarte a ti mismo por seguridad.'], 403);
        }

        if (!$user->isActive()) {
            return $this->json(['error' => 'that user is already deleted'], 400);
        }

        // Soft delete and append .deleted.timestamp to free up the email
        $timestamp = time();
        $user->setEmail($user->getEmail() . '.deleted.' . $timestamp);
        $user->setDeletedAt(new \DateTime());
        $em->flush();

        return $this->json(['message' => 'Usuario desactivado correctamente (borrado lógico)']);
    }

    /**
     * @Route("/{id}/toggle-active", methods={"POST"})
     * @OA\Post(
     *     path="/api/users/{id}/toggle-active",
     *     summary="Toggle user active status (soft delete/restore)",
     *     tags={"Usuarios"},
     *     @OA\Parameter(name="id", in="path", description="User ID", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Status toggled successfully"),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function toggleActive(int $id, UserRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        $user = $repository->find($id);
        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Prevent self-deactivation
        if ($this->getUser() && $this->getUser()->getId() === $user->getId()) {
            return $this->json(['error' => 'No puedes cambiar tu propio estado de actividad por seguridad.'], 403);
        }

        if ($user->isActive()) {
            // Deactivating: append .deleted.timestamp to email
            $timestamp = time();
            $user->setEmail($user->getEmail() . '.deleted.' . $timestamp);
            $user->setDeletedAt(new \DateTime());
        } else {
            // Activating: check if original email is free
            $originalEmail = preg_replace('/\.deleted\.\d+$/', '', $user->getEmail());

            $existingUser = $repository->findOneBy(['email' => $originalEmail]);
            if ($existingUser && $existingUser->getId() !== $user->getId()) {
                return $this->json([
                    'error' => 'No se puede reactivar el usuario. Ya existe otra cuenta usando el correo: ' . $originalEmail
                ], 400);
            }

            $user->setEmail($originalEmail);
            $user->setDeletedAt(null);
        }

        $em->flush();

        return $this->json([
            'message' => $user->isActive() ? 'Usuario activado' : 'Usuario desactivado',
            'isActive' => $user->isActive()
        ]);
    }
}
