<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Repository\UserRepository;
use OpenApi\Annotations as OA;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;

class AuthController extends AbstractController
{
    /**
     * @Route("/api/register", name="api_register", methods={"POST"})
     * @OA\Post(
     * path="/api/register",
     * summary="Register a new user into the database",
     * tags={"Usuarios"},
     * @OA\RequestBody(
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="email", type="string", example="admin@intranet.com"),
     * @OA\Property(property="name", type="string", example="Juan"),
     * @OA\Property(property="surname", type="string", example="Pérez"),
     * @OA\Property(property="role", type="string", example="ROLE_USER"),
     * @OA\Property(property="password", type="string", example="mi_password_seguro")
     * )
     * ),
     * @OA\Response(response=201, description="User Creation successful"),
     * @OA\Response(response=400, description="Validation error"),
     * @OA\Response(response=403, description="Insufficient permissions")
     * )
     */
    public function register(Request $request, UserPasswordEncoderInterface $encoder, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validate the JSON 
        if (!$data || !is_array($data)) {
            return new JsonResponse(['error' => 'Datos inválidos o JSON malformado'], 400);
        }

        // Only users with ROLE_ADMIN can register new users
        if (!$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['error' => 'No tienes permisos para registrar usuarios.'], 403);
        }

        // Populates request data into DTO
        $dto = \App\Dto\RegisterInput::fromArray($data);

        // Validates constraints using Symfony's Validator
        $errors = $validator->validate($dto);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse(['error' => implode(' ', $errorMessages)], 400);
        }

        $role = $dto->role ?? 'ROLE_USER';

        // Only a Super Admin can create Admin or Super Admin accounts
        if (in_array($role, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']) && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            return new JsonResponse(['error' => 'No tienes permisos para crear una cuenta con ese rol.'], 403);
        }

        // Create and populate the User entity
        $user = $dto->toEntity($encoder);

        // Validate the entity (e.g. UniqueEntity check on the database)
        $entityErrors = $validator->validate($user);
        if (count($entityErrors) > 0) {
            $errorMessages = [];
            foreach ($entityErrors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse(['error' => implode(' ', $errorMessages)], 400);
        }

        // Save into the database
        try {
            $em->persist($user);
            $em->flush();
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Error al guardar el usuario en la base de datos. Verifique que los datos sean correctos.'], 500);
        }

        return new JsonResponse(['message' => 'Usuario creado exitosamente'], 201);
    }

    /**
     * @Route("/api/me", name="api_me", methods={"GET"})
     * @OA\Get(
     *     path="/api/me",
     *     summary="Returns the profile of the currently authenticated user",
     *     tags={"Autenticación"},
     *     @OA\Response(
     *         response=200,
     *         description="User profile",
     *         @OA\JsonContent(
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="surname", type="string"),
     *             @OA\Property(property="roles", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="mustChangePassword", type="boolean"),
     *             @OA\Property(property="isActive", type="boolean")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Not authenticated")
     * )
     */
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        // Must be logged in to use it
        if (!$user) {
            return new JsonResponse(['error' => 'No autenticado'], 401);
        }
        // Returns formatted response
        return new JsonResponse([
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'surname' => $user->getSurname(),
            'roles' => $user->getRoles(),
            'mustChangePassword' => $user instanceof \App\Entity\User ? $user->getMustChangePassword() : false,
            'isActive' => $user instanceof \App\Entity\User ? $user->isActive() : false,
        ]);
    }

    /**
     * @Route("/api/logout", name="api_logout", methods={"POST"})
     * @OA\Post(
     *     path="/api/logout",
     *     summary="Revokes a refresh token to logout a user",
     *     tags={"Autenticación"},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="refresh_token", type="string", example="abcdef1234567890...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Token revoked successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Token revocado")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Invalid refresh token")
     * )
     */
    public function logout(Request $request, RefreshTokenManagerInterface $refreshTokenManager): JsonResponse
    {
        // Decode the request body to extract the refresh token
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refresh_token'] ?? null;

        // Checks if the refresh token was received
        if (!$refreshTokenString) {
            return new JsonResponse(['error' => 'No se proporcionó el refresh token'], 400);
        }

        $refreshToken = $refreshTokenManager->get($refreshTokenString);

        // If token is invalid return 400
        if (!$refreshToken) {
            return new JsonResponse(['error' => 'Refresh token inválido'], 400);
        }

        // Deletes the token from the database to revoke it and log the user out
        $refreshTokenManager->delete($refreshToken);

        return new JsonResponse(['message' => 'Sesión cerrada y token revocado']);
    }

    /**
     * @Route("/api/check-password", name="api_check_password", methods={"POST"})
     * @OA\Post(
     *     path="/api/check-password",
     *     summary="Checks if the provided password matches the current user's password",
     *     tags={"Autenticación"},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="current_password", type="string", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password match result",
     *         @OA\JsonContent(
     *             @OA\Property(property="match", type="boolean")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Invalid request")
     * )
     */
    public function checkPassword(Request $request, UserPasswordEncoderInterface $passwordEncoder): JsonResponse
    {
        // Obtains the user
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Usuario no autenticado'], 401);
        }

        // Decodes the request body to get current_password value
        $data = json_decode($request->getContent(), true);
        $currentPassword = $data['current_password'] ?? null;

        // If token is doesn´t exist return 400
        if (empty($currentPassword)) {
            return new JsonResponse(['error' => 'Debe proporcionar la contraseña actual'], 400);
        }

        // Compares password with DB
        $isValid = $passwordEncoder->isPasswordValid($user, $currentPassword);

        if (!$isValid) {
            return new JsonResponse(['error' => 'La contraseña actual no es la correcta'], 400);
        }

        return new JsonResponse([
            'match' => true,
            'message' => 'Contraseña verificada correctamente'
        ]);
    }
}
