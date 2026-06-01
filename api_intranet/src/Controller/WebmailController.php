<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CpanelWebmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * Controller to expose SSO authentication services for Corporate Webmail.
 */
class WebmailController extends AbstractController
{
    private $webmailService;

    public function __construct(CpanelWebmailService $webmailService)
    {
        $this->webmailService = $webmailService;
    }

    /**
     * @Route("/api/webmail/sso-token", name="api_webmail_sso_token", methods={"POST"})
     * @OA\Post(
      *     path="/api/webmail/sso-token",
      *     summary="Generates a temporary Single Sign-On (SSO) login token for cPanel Webmail",
      *     tags={"Webmail SSO"},
      *     @OA\Response(
      *         response=200,
      *         description="SSO session created successfully",
      *         @OA\JsonContent(
      *             @OA\Property(property="session", type="string", example="example_session_value"),
      *             @OA\Property(property="token", type="string", example="/cpsess1234567890"),
      *             @OA\Property(property="hostname", type="string", example="mail.company.com")
      *         )
      *     ),
      *     @OA\Response(
      *         response=401,
      *         description="Not authenticated"
      *     ),
      *     @OA\Response(
      *         response=400,
      *         description="Failed to create session or malformed user metadata",
      *         @OA\JsonContent(
      *             @OA\Property(property="error", type="string", example="No se pudo crear la sesión de correo: Cuenta suspendida")
      *         )
      *     )
      * )
     */
    public function getSsoToken(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Usuario no autenticado'], 401);
        }

        $email = $user->getEmail();
        if (empty($email)) {
            return new JsonResponse(['error' => 'El usuario no tiene una dirección de correo configurada.'], 400);
        }

        try {
            // Generate the secure cPanel Webmail temporary session parameters
            $ssoData = $this->webmailService->createWebmailSession($email);
            
            return new JsonResponse($ssoData, 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error al generar acceso directo a Webmail: ' . $e->getMessage()
            ], 400);
        }
    }
}
