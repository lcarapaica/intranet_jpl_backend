<?php

namespace App\Controller;

use App\Entity\CalendarEvent;
use App\Entity\User;
use App\Repository\CalendarEventRepository;
use App\Repository\UserRepository;
use App\Dto\CalendarEventInput;
use App\Dto\CalendarEventOutput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use OpenApi\Annotations as OA;

/**
 * Controller for managing User and Company Calendar Events.
 * 
 * @Route("/api/calendar", name="api_calendar_")
 */
class CalendarController extends AbstractController
{
    /**
     * Lists aggregated personal and company-wide calendar events with filters.
     * 
     * @Route("", name="list", methods={"GET"})
     * @OA\Get(
     *     path="/api/calendar",
     *     summary="List calendar events for the authenticated user",
     *     tags={"Calendario"},
     *     @OA\Parameter(name="start", in="query", description="Filter start date (YYYY-MM-DD)", @OA\Schema(type="string")),
     *     @OA\Parameter(name="end", in="query", description="Filter end date (YYYY-MM-DD)", @OA\Schema(type="string")),
     *     @OA\Parameter(name="tag", in="query", description="Filter by event tag (e.g. 'tecnologia', 'JPL')", @OA\Schema(type="string")),
     *     @OA\Parameter(name="type", in="query", description="Filter by type (personal or company)", @OA\Schema(type="string")),
     *     @OA\Parameter(name="isActive", in="query", description="Filter by active status (true to list active events, false to list soft-deleted ones) - Admins/Editors only", @OA\Schema(type="boolean", default=true)),
     *     @OA\Response(response=200, description="List of calendar events")
     * )
     */
    public function list(Request $request, CalendarEventRepository $repository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $start = $request->query->get('start', '');
        $end = $request->query->get('end', '');
        $tag = $request->query->get('tag', '');
        $type = $request->query->get('type', '');

        $isActiveParam = $request->query->get('isActive', 'true');
        // Parse the boolean filter, falling back to active events if the param is invalid or omitted
        $isActive = filter_var($isActiveParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        // Admins and editors are allowed to include soft-deleted calendar events in the feed
        $isAdminOrEditor = $this->isGranted('ROLE_CALENDAR_EDITOR');

        $events = $repository->findUserEventsFeed($user, [
            'start' => $start,
            'end'   => $end,
            'tag'   => $tag,
            'type'  => $type,
            'include_deleted' => $isAdminOrEditor && !$isActive,
            'only_deleted' => $isAdminOrEditor && !$isActive
        ]);

        $data = [];
        foreach ($events as $event) {
            $data[] = CalendarEventOutput::fromEntity($event, $isAdminOrEditor);
        }

        return $this->json($data);
    }

    /**
     * Fetches active upcoming reminders for the user (perfect for dashboard integration).
     * 
     * @Route("/reminders", name="reminders", methods={"GET"})
     * @OA\Get(
     *     path="/api/calendar/reminders",
     *     summary="Fetch active calendar event reminders",
     *     tags={"Calendario"},
     *     @OA\Response(response=200, description="List of active event reminders")
     * )
     */
    public function reminders(CalendarEventRepository $repository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $now = new \DateTime();

        $reminders = $repository->findUpcomingReminders($user, $now);

        $data = [];
        foreach ($reminders as $event) {
            $data[] = CalendarEventOutput::fromEntity($event);
        }

        return $this->json($data);
    }

    /**
     * Retrieves details of a single calendar event.
     * 
     * @Route("/{id}", name="show", methods={"GET"}, requirements={"id"="\d+"})
     * @OA\Get(
     *     path="/api/calendar/{id}",
     *     summary="Get details of a single event",
     *     tags={"Calendario"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Calendar event details"),
     *     @OA\Response(response=404, description="Event not found or access denied")
     * )
     */
    public function show(int $id, CalendarEventRepository $repository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $event = $repository->find($id);

        $isAdminOrEditor = $this->isGranted('ROLE_CALENDAR_EDITOR');

        // Only calendar editors and admins are allowed to retrieve soft-deleted events
        if (!$event || ($event->getDeletedAt() !== null && !$isAdminOrEditor)) {
            return $this->json(['error' => 'Evento no encontrado'], 404);
        }

        // Users can only see company-wide events, events they created themselves, or events where they are a participant
        if (!$event->getIsCompanyWide() && $event->getOwner() !== $user && !$event->getParticipants()->contains($user)) {
            return $this->json(['error' => 'Acceso denegado a este evento'], 403);
        }

        return $this->json(CalendarEventOutput::fromEntity($event, $isAdminOrEditor));
    }

    /**
     * Creates a new calendar event (personal or company-wide).
     * 
     * @Route("", name="create", methods={"POST"})
     * @OA\Post(
     *     path="/api/calendar",
     *     summary="Create a new calendar event",
     *     tags={"Calendario"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="title", type="string", example="Reunión de Tecnología"),
     *             @OA\Property(property="description", type="string", example="Planificación semanal"),
     *             @OA\Property(property="place", type="string", example="Sala de reuniones A", nullable=true),
     *             @OA\Property(property="date", type="string", format="date", example="2026-06-01"),
     *             @OA\Property(property="startAt", type="string", format="date-time", example="2026-06-01 10:00:00", nullable=true),
     *             @OA\Property(property="endAt", type="string", format="date-time", example="2026-06-01 11:30:00", nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), example={"tecnologia", "reunion"}),
     *             @OA\Property(property="isCompanyWide", type="boolean", example=false),
     *             @OA\Property(property="reminderAt", type="string", format="date-time", example="2026-06-01 09:30:00", nullable=true),
     *             @OA\Property(property="cliente", type="string", example="Cliente XYZ", nullable=true),
     *             @OA\Property(property="color", type="string", example="#FFAA00", nullable=true),
     *             @OA\Property(property="participants", type="array", @OA\Items(type="integer"), example={1, 2})
     *         )
     *     ),
     *     @OA\Response(response=201, description="Event successfully created"),
     *     @OA\Response(response=400, description="Invalid request or validation error"),
     *     @OA\Response(response=403, description="Insufficient permissions to create company-wide events")
     * )
     */
    public function create(Request $request, EntityManagerInterface $em, ValidatorInterface $validator, UserRepository $userRepository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?: [];

        $isCompanyWideInput = isset($data['isCompanyWide']) && (bool)$data['isCompanyWide'];

        // Enforce calendar editor permissions for company-wide events
        if ($isCompanyWideInput && !$this->isGranted('ROLE_CALENDAR_EDITOR')) {
            return $this->json(['error' => 'No tienes permisos para crear eventos globales/de la empresa.'], 403);
        }

        $event = new CalendarEvent();

        // Always set the owner/creator of the event
        $event->setOwner($user);

        $dto = CalendarEventInput::fromArray($data);

        try {
            $dto->updateEntity($event, array_keys($data));
        } catch (\Exception $e) {
            return $this->json(['error' => 'Formato de fecha inválido: ' . $e->getMessage()], 400);
        }

        // Handle participants mapping
        $this->handleParticipants($event, $data, array_keys($data), $userRepository, $user);

        // Entity validation check
        $errors = $validator->validate($event);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        // Validate that both times are present together if one of them is defined
        if (($event->getStartAt() === null && $event->getEndAt() !== null) || ($event->getStartAt() !== null && $event->getEndAt() === null)) {
            return $this->json(['error' => 'Si defines una hora de inicio, también debes definir una hora de término (y viceversa).'], 400);
        }

        // Ensure chronological validity of dates
        if ($event->getStartAt() !== null && $event->getEndAt() !== null && $event->getStartAt() > $event->getEndAt()) {
            return $this->json(['error' => 'El evento no puede terminar antes de empezar.'], 400);
        }

        $em->persist($event);
        $em->flush();

        return $this->json([
            'message' => 'Evento creado exitosamente',
            'id'      => $event->getId()
        ], 201);
    }

    /**
     * Updates an existing calendar event.
     * 
     * @Route("/{id}", name="update", methods={"PUT"}, requirements={"id"="\d+"})
     * @OA\Put(
     *     path="/api/calendar/{id}",
     *     summary="Update an existing calendar event",
     *     tags={"Calendario"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="title", type="string", example="Reunión Actualizada"),
     *             @OA\Property(property="description", type="string", example="Nueva descripción"),
     *             @OA\Property(property="place", type="string", example="Sala de reuniones A", nullable=true),
     *             @OA\Property(property="date", type="string", format="date", example="2026-06-01"),
     *             @OA\Property(property="startAt", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="endAt", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="reminderAt", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="cliente", type="string", example="Cliente XYZ", nullable=true),
     *             @OA\Property(property="color", type="string", example="#FFAA00", nullable=true),
     *             @OA\Property(property="participants", type="array", @OA\Items(type="integer"), example={1, 2})
     *         )
     *     ),
     *     @OA\Response(response=200, description="Event successfully updated"),
     *     @OA\Response(response=403, description="Unauthorized to edit this event"),
     *     @OA\Response(response=404, description="Event not found")
     * )
     */
    public function update(int $id, Request $request, CalendarEventRepository $repository, EntityManagerInterface $em, ValidatorInterface $validator, UserRepository $userRepository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $event = $repository->find($id);

        if (!$event || $event->getDeletedAt() !== null) {
            return $this->json(['error' => 'Evento no encontrado'], 404);
        }

        // Validate edit permissions based on scope: global events require editor role, personal ones require ownership
        if ($event->getIsCompanyWide()) {
            if (!$this->isGranted('ROLE_CALENDAR_EDITOR')) {
                return $this->json(['error' => 'No tienes permisos para modificar eventos globales.'], 403);
            }
        } else {
            if ($event->getOwner() !== $user) {
                return $this->json(['error' => 'No tienes permisos para modificar este evento personal.'], 403);
            }
        }

        $data = json_decode($request->getContent(), true) ?: [];

        // Prevent changing isCompanyWide scope after creation to maintain structural integrity
        unset($data['isCompanyWide']);

        $dto = CalendarEventInput::fromArray($data);

        try {
            $dto->updateEntity($event, array_keys($data));
        } catch (\Exception $e) {
            return $this->json(['error' => 'Formato de fecha inválido: ' . $e->getMessage()], 400);
        }

        // Handle participants mapping
        $this->handleParticipants($event, $data, array_keys($data), $userRepository, $user);

        $errors = $validator->validate($event);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        // Validate that both times are present together if one of them is defined
        if (($event->getStartAt() === null && $event->getEndAt() !== null) || ($event->getStartAt() !== null && $event->getEndAt() === null)) {
            return $this->json(['error' => 'Si defines una hora de inicio, también debes definir una hora de término (y viceversa).'], 400);
        }

        // Ensure chronological validity of dates
        if ($event->getStartAt() !== null && $event->getEndAt() !== null && $event->getStartAt() > $event->getEndAt()) {
            return $this->json(['error' => 'El evento no puede terminar antes de empezar.'], 400);
        }

        $em->flush();

        return $this->json(['message' => 'Evento actualizado correctamente']);
    }

    /**
     * Soft-deletes a calendar event.
     * 
     * @Route("/{id}", name="delete", methods={"DELETE"}, requirements={"id"="\d+"})
     * @OA\Delete(
     *     path="/api/calendar/{id}",
     *     summary="Soft delete a calendar event",
     *     tags={"Calendario"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Event logically deleted successfully"),
     *     @OA\Response(response=403, description="Unauthorized to delete this event"),
     *     @OA\Response(response=404, description="Event not found")
     * )
     */
    public function delete(int $id, CalendarEventRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $event = $repository->find($id);

        if (!$event || $event->getDeletedAt() !== null) {
            return $this->json(['error' => 'Evento no encontrado'], 404);
        }

        // Global events can be deleted by their creator or an editor, whereas personal events can only be deleted by their owner
        if ($event->getIsCompanyWide()) {
            $isCreator = ($event->getOwner() === $user);
            $isEditor = $this->isGranted('ROLE_CALENDAR_EDITOR');
            if (!$isCreator && !$isEditor) {
                return $this->json(['error' => 'No tienes permisos para eliminar eventos globales que no creaste.'], 403);
            }
        } else {
            if ($event->getOwner() !== $user) {
                return $this->json(['error' => 'No tienes permisos para eliminar este evento personal.'], 403);
            }
        }

        // Soft-delete the event by record timestamping
        $event->setDeletedAt(new \DateTime());
        $em->flush();

        return $this->json(['message' => 'Evento eliminado correctamente']);
    }

    /**
     * Toggles the active status (soft-delete or restore) of a calendar event.
     * 
     * @Route("/{id}/toggle-active", name="toggle_active", methods={"PATCH"}, requirements={"id"="\d+"})
     * @OA\Patch(
     *     path="/api/calendar/{id}/toggle-active",
     *     summary="Toggle active status of a calendar event",
     *     tags={"Calendario"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Active status toggled successfully"),
     *     @OA\Response(response=403, description="Unauthorized to toggle status for this event"),
     *     @OA\Response(response=404, description="Event not found")
     * )
     */
    public function toggleActive(int $id, CalendarEventRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Find regardless of soft-delete state
        $event = $repository->find($id);

        if (!$event) {
            return $this->json(['error' => 'Evento no encontrado'], 404);
        }

        // Global events can be updated by their creator or an editor, whereas personal events require ownership
        if ($event->getIsCompanyWide()) {
            $isCreator = ($event->getOwner() === $user);
            $isEditor = $this->isGranted('ROLE_CALENDAR_EDITOR');
            if (!$isCreator && !$isEditor) {
                return $this->json(['error' => 'No tienes permisos para modificar eventos globales que no creaste.'], 403);
            }
        } else {
            if ($event->getOwner() !== $user) {
                return $this->json(['error' => 'No tienes permisos para modificar este evento personal.'], 403);
            }
        }

        // Toggle logical state by altering the deletion timestamp
        if ($event->getDeletedAt() === null) {
            $event->setDeletedAt(new \DateTime());
            $message = 'Evento desactivado (eliminado lógicamente) correctamente';
        } else {
            $event->setDeletedAt(null);
            $message = 'Evento reactivado correctamente';
        }

        $em->flush();

        return $this->json([
            'message'  => $message,
            'isActive' => $event->isActive()
        ]);
    }

    /**
     * Helper to set participants on CalendarEvent according to rules.
     */
    private function handleParticipants(
        CalendarEvent $event,
        array $data,
        ?array $providedFields,
        UserRepository $userRepository,
        User $currentUser
    ): void {
        // If this is an update and 'participants' key was not provided, do nothing
        if ($providedFields !== null && !in_array('participants', $providedFields)) {
            return;
        }

        // If the event isn't company-wide
        if (!$event->getIsCompanyWide()) {
            if (!isset($data['participants']) || !is_array($data['participants']) || empty($data['participants'])) {
                // If none written, then only the creator
                $event->getParticipants()->clear();
                $event->addParticipant($event->getOwner() ?? $currentUser);
            } else {
                $event->getParticipants()->clear();
                foreach ($data['participants'] as $pId) {
                    $pUser = $userRepository->find($pId);
                    if ($pUser) {
                        $event->addParticipant($pUser);
                    }
                }
            }
        } else {
            // Company-wide event: set participants if provided, otherwise leave empty or clear
            $event->getParticipants()->clear();
            if (isset($data['participants']) && is_array($data['participants'])) {
                foreach ($data['participants'] as $pId) {
                    $pUser = $userRepository->find($pId);
                    if ($pUser) {
                        $event->addParticipant($pUser);
                    }
                }
            }
        }
    }
}
