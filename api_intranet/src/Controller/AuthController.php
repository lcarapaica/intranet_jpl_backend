<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use OpenApi\Annotations as OA;

class AuthController extends AbstractController
{
    /**
     * @Route("/api/register", name="api_register", methods={"POST"})
     * @OA\Post(
     * path="/api/register",
     * summary="Register a new user into the database",
     * tags={"Autenticación"},
     * @OA\RequestBody(
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="email", type="string", example="admin@intranet.com"),
     * @OA\Property(property="password", type="string", example="mi_password_seguro")
     * )
     * ),
     * @OA\Response(response=201, description="User Creation successful"),
     * @OA\Response(response=400, description="Validation error")
     * )
     */
    public function register(Request $request, UserPasswordEncoderInterface $encoder, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Set Validation
        if (!isset($data['email']) || !isset($data['password'])) {
            return new JsonResponse(['error' => 'Email y password son obligatorios'], 400);
        }

        // Create user
        $user = new User();
        $user->setEmail($data['email']);

        // Hash Password
        $hashedPassword = $encoder->encodePassword($user, $data['password']);
        $user->setPassword($hashedPassword);

        $user->setRoles(['ROLE_USER']);

        // Save into the database
        $em->persist($user);
        $em->flush();

        return new JsonResponse(['message' => 'Usuario creado exitosamente'], 201);
    }

    /**
     * @Route("/api/me", name="api_me", methods={"GET"})
     */
    //Returns user info
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse(['error' => 'No autenticado'], 401);
        }

        return new JsonResponse([
            'email' => $user->getUsername(),
            'roles' => $user->getRoles(),
        ]);
    }
}
