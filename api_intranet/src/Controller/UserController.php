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
     *     summary="List all users",
     *     tags={"Users"},
     *     @OA\Response(response=200, description="List of users"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    //Returns a list of all the users
    public function index(UserRepository $repository): JsonResponse
    {
        $users = $repository->findAll();
        $data = [];

        foreach ($users as $user) {
            $data[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ];
        }

        return $this->json($data);
    }

    /**
     * @Route("/{id}", methods={"PUT"})
     * @OA\Put(
     *     path="/api/users/{id}",
     *     summary="Update a user",
     *     tags={"Users"},
     *     @OA\Parameter(name="id", in="path", description="User ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="email", type="string", example="user@example.com"),
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
    // Updates the credentials or roles of an user
    public function update(int $id, Request $request, UserRepository $repository, EntityManagerInterface $em, UserPasswordEncoderInterface $encoder, ValidatorInterface $validator): JsonResponse
    {
        $user = $repository->find($id);
        // If the user doesn't exist, throw error
        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        // Checks if the user is able to edit the target user, if not blocks access
        $this->denyAccessUnlessGranted('USER_EDIT', $user);
        // If allowed, turns the JSON into PHP
        $data = json_decode($request->getContent(), true);

        // Maps array into DTO
        $dto = new \App\Dto\UserUpdateDto();
        $dto->email = $data['email'] ?? null;
        $dto->roles = $data['roles'] ?? null;
        $dto->password = $data['password'] ?? null;

        // Validates data
        $errors = $validator->validate($dto);
        if (count($errors) > 0) {
            return $this->json(['error' => (string) $errors], 400);
        }

        // Checks if user has permission to edit an user role, if not, disallows
        $canEditRoles = $this->isGranted('USER_EDIT_ROLES', $user);
        $isTryingToGrantSuper = $dto->roles && in_array('ROLE_SUPER_ADMIN', $dto->roles);
        // Calculate if a role change to super user is allowed
        $canChangeRoles = $canEditRoles && (!$isTryingToGrantSuper || $this->isGranted('ROLE_SUPER_ADMIN'));

        // Checks if user has permission to change roles of another
        if ($dto->roles !== null && !$canChangeRoles) {
            return $this->json([
                'error' => 'No tienes permisos para modificar los roles de este usuario o asignar el rango solicitado.'
            ], 403);
        }

        // // Check that role isn't applied twice
        // if ($dto->roles !== null) {
        //     $currentRoles = $user->getRoles();

        //     // Compare the first role in the DB with the first role in the DTO
        //     if (isset($currentRoles[0]) && isset($dto->roles[0]) && $currentRoles[0] === $dto->roles[0]) {
        //         return $this->json([
        //             'warning' => 'El usuario ya tiene asignado el rol: ' . $dto->roles[0]
        //         ], 200);
        //     }
        // }

        //updates DB with values
        $dto->updateEntity($user, $encoder, $canChangeRoles);
        $em->flush();

        return $this->json(['message' => 'Usuario actualizado correctamente']);
    }

    /**
     * @Route("/{id}", methods={"DELETE"})
     * @OA\Delete(
     *     path="/api/users/{id}",
     *     summary="Delete a user",
     *     tags={"Users"},
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

        $em->remove($user);
        $em->flush();

        return $this->json(['message' => 'Usuario eliminado correctamente']);
    }
}
