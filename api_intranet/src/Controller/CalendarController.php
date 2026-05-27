<?php

namespace App\Controller;

use App\Entity\CalendarEvent;
use App\Entity\User;
use App\Repository\CalendarEventRepository;
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

        $events = $repository->findUserEventsFeed($user, [
            'start' => $start,
            'end'   => $end,
            'tag'   => $tag,
            'type'  => $type
        ]);

        $data = [];
        foreach ($events as $event) {
            $data[] = CalendarEventOutput::fromEntity($event);
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

        if (!$event || $event->getDeletedAt() !== null) {
            return $this->json(['error' => 'Evento no encontrado'], 404);
        }

        // Access check: must be company-wide OR owned by the requester
        if (!$event->getIsCompanyWide() && $event->getOwner() !== $user) {
            return $this->json(['error' => 'Acceso denegado a este evento'], 403);
        }

        return $this->json(CalendarEventOutput::fromEntity($event));
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
     *             @OA\Property(property="startAt", type="string", format="date-time", example="2026-06-01 10:00:00"),
     *             @OA\Property(property="endAt", type="string", format="date-time", example="2026-06-01 11:30:00"),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string"), example={"tecnologia", "reunion"}),
     *             @OA\Property(property="isCompanyWide", type="boolean", example=false),
     *             @OA\Property(property="reminderAt", type="string", format="date-time", example="2026-06-01 09:30:00", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Event successfully created"),
     *     @OA\Response(response=400, description="Invalid request or validation error"),
     *     @OA\Response(response=403, description="Insufficient permissions to create company-wide events")
     * )
     */
    public function create(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
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
        
        // If it's a personal event, set the owner
        if (!$isCompanyWideInput) {
            $event->setOwner($user);
        }

        $dto = CalendarEventInput::fromArray($data);
        
        try {
            $dto->updateEntity($event, array_keys($data));
        } catch (\Exception $e) {
            return $this->json(['error' => 'Formato de fecha inválido: ' . $e->getMessage()], 400);
        }

        // Entity validation check
        $errors = $validator->validate($event);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        // Date validation: startAt must be before endAt
        if ($event->getStartAt() >= $event->getEndAt()) {
            return $this->json(['error' => 'La fecha de inicio debe ser anterior a la de término.'], 400);
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
     *             @OA\Property(property="startAt", type="string", format="date-time"),
     *             @OA\Property(property="endAt", type="string", format="date-time"),
     *             @OA\Property(property="tags", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="reminderAt", type="string", format="date-time", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Event successfully updated"),
     *     @OA\Response(response=403, description="Unauthorized to edit this event"),
     *     @OA\Response(response=404, description="Event not found")
     * )
     */
    public function update(int $id, Request $request, CalendarEventRepository $repository, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $event = $repository->find($id);

        if (!$event || $event->getDeletedAt() !== null) {
            return $this->json(['error' => 'Evento no encontrado'], 404);
        }

        // Authorization checks
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
        
        // Prevent changing isCompanyWide status via update for consistency
        unset($data['isCompanyWide']);

        $dto = CalendarEventInput::fromArray($data);
        
        try {
            $dto->updateEntity($event, array_keys($data));
        } catch (\Exception $e) {
            return $this->json(['error' => 'Formato de fecha inválido: ' . $e->getMessage()], 400);
        }

        $errors = $validator->validate($event);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        if ($event->getStartAt() >= $event->getEndAt()) {
            return $this->json(['error' => 'La fecha de inicio debe ser anterior a la de término.'], 400);
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

        // Authorization checks
        if ($event->getIsCompanyWide()) {
            if (!$this->isGranted('ROLE_CALENDAR_EDITOR')) {
                return $this->json(['error' => 'No tienes permisos para eliminar eventos globales.'], 403);
            }
        } else {
            if ($event->getOwner() !== $user) {
                return $this->json(['error' => 'No tienes permisos para eliminar este evento personal.'], 403);
            }
        }

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

        // Authorization checks
        if ($event->getIsCompanyWide()) {
            if (!$this->isGranted('ROLE_CALENDAR_EDITOR')) {
                return $this->json(['error' => 'No tienes permisos para modificar eventos globales.'], 403);
            }
        } else {
            if ($event->getOwner() !== $user) {
                return $this->json(['error' => 'No tienes permisos para modificar este evento personal.'], 403);
            }
        }

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
}
