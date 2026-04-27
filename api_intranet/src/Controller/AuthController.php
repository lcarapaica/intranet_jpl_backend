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
     * summary="Registrar un nuevo usuario en la base de datos",
     * tags={"Autenticación"},
     * @OA\RequestBody(
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(property="email", type="string", example="admin@intranet.com"),
     * @OA\Property(property="password", type="string", example="mi_password_seguro")
     * )
     * ),
     * @OA\Response(response=201, description="Usuario creado exitosamente"),
     * @OA\Response(response=400, description="Error de validación")
     * )
     */
    public function register(Request $request, UserPasswordEncoderInterface $encoder, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validaciones básicas
        if (!isset($data['email']) || !isset($data['password'])) {
            return new JsonResponse(['error' => 'Email y password son obligatorios'], 400);
        }

        // Crear el usuario
        $user = new User();
        $user->setEmail($data['email']);

        // Encriptar la contraseña (Regla de seguridad)
        $hashedPassword = $encoder->encodePassword($user, $data['password']);
        $user->setPassword($hashedPassword);

        $user->setRoles(['ROLE_USER']);

        // Guardar en base de datos
        $em->persist($user);
        $em->flush();

        return new JsonResponse(['message' => 'Usuario creado exitosamente'], 201);
    }

    /**
     * @Route("/api/me", name="api_me", methods={"GET"})
     */
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
