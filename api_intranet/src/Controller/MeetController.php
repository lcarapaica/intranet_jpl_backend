<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\GoogleMeetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * Controller to manage instant ad-hoc Google Meet creation outside of specific conversations.
 * 
 * @Route("/api/meet", name="api_meet_")
 */
class MeetController extends AbstractController
{
    /**
     * Creates an instant Google Meet room and returns the room URI.
     * 
     * @Route("/instant", name="create_instant", methods={"POST"})
     * 
     * @OA\Post(
     *     path="/api/meet/instant",
     *     summary="Create an instant Google Meet call outside of a conversation context",
     *     tags={"Reuniones"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="participantIds",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 example={2, 3},
     *                 description="List of user IDs to invite to the call"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Google Meet link successfully generated"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request parameter format"
     *     )
     * )
     */
    public function createInstant(Request $request, UserRepository $userRepository, GoogleMeetService $meetService): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $data = json_decode($request->getContent(), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Formato JSON inválido'], 400);
        }

        $participantIds = $data['participantIds'] ?? [];
        if (!is_array($participantIds)) {
            return $this->json(['error' => 'El campo participantIds debe ser un arreglo.'], 400);
        }

        // Get attendee emails for pre-authorization and quick join bypass
        $attendeeEmails = [];
        foreach ($participantIds as $id) {
            $user = $userRepository->find($id);
            if ($user && $user->isActive()) {
                $attendeeEmails[] = $user->getEmail();
            }
        }

        // Add the creator's email to the attendee list if not already present
        if ($currentUser && !in_array($currentUser->getEmail(), $attendeeEmails)) {
            $attendeeEmails[] = $currentUser->getEmail();
        }

        // Generate the meeting room space using Google Meet API
        $title = sprintf("Reunión Instantánea - %s", $currentUser ? $currentUser->getDisplayName() : 'Intranet');
        $meetUrl = $meetService->createSpace($title, $attendeeEmails);

        return $this->json([
            'meetUrl' => $meetUrl,
            'title' => $title,
            'attendees' => $attendeeEmails
        ]);
    }
}
