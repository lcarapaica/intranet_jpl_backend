<?php

namespace App\Controller;

use App\Entity\KanbanTask;
use App\Entity\User;
use App\Repository\KanbanTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * Controller for managing the personal Kanban Board.
 * 
 * @Route("/api/board", name="api_board_")
 */
class KanbanController extends AbstractController
{
    /**
     * Get tasks for the authenticated user, optionally filtered by active status.
     * 
     * @Route("", name="list", methods={"GET"})
     * 
     * @OA\Get(
     *     summary="Retrieves tasks for the current user's personal board, filtered by active status",
     *     tags={"Kanban Board"},
     *     @OA\Parameter(
     *         name="active",
     *         in="query",
     *         description="Filter tasks by active status (true to list active tasks, false to list soft-deleted ones)",
     *         required=false,
     *         @OA\Schema(type="string", default="true")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of tasks sorted by custom position, then by creation date",
     *         @OA\JsonContent(type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="category", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="importance", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="subTasks", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="message", type="string", nullable=true),
     *             @OA\Property(property="dueAt", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="position", type="integer"),
     *             @OA\Property(property="createdAt", type="string"),
     *             @OA\Property(property="updatedAt", type="string"),
     *             @OA\Property(property="deletedAt", type="string", format="date-time", nullable=true)
     *         ))
     *     )
     * )
     */
    public function list(Request $request, KanbanTaskRepository $repository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Check if the 'active' parameter is present and determine its boolean value. Defaults to true if not specified.
        $activeParam = $request->query->get('active', 'true');
        $isActive = filter_var($activeParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $qb = $repository->createQueryBuilder('t')
            ->where('t.owner = :owner')
            ->setParameter('owner', $user);

        if ($isActive) {
            $qb->andWhere('t.deletedAt IS NULL');
        } else {
            $qb->andWhere('t.deletedAt IS NOT NULL');
        }

        $qb->orderBy('t.position', 'ASC')
           ->addOrderBy('t.createdAt', 'DESC');

        $tasks = $qb->getQuery()->getResult();

        return $this->json($tasks, 200, [], ['groups' => 'kanban:read']);
    }

    /**
     * Create a new task for the authenticated user.
     * 
     * @Route("", name="create", methods={"POST"})
     * 
     * @OA\Post(
     *     summary="Creates a new task in the user's personal board",
     *     tags={"Kanban Board"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Fix login bug"),
     *             @OA\Property(property="category", type="array", @OA\Items(type="string"), example={"Development", "Frontend"}),
     *             @OA\Property(property="importance", type="string", example="alta"),
     *             @OA\Property(property="status", type="string", example="Por Hacer"),
     *             @OA\Property(property="message", type="string", example="The bug occurs in Safari when attempting to log in.", nullable=true),
     *             @OA\Property(property="dueAt", type="string", format="date-time", example="2026-05-30T12:00:00Z", nullable=true),
     *             @OA\Property(property="position", type="integer", example=0),
     *             @OA\Property(
     *                 property="subTasks",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="title", type="string", example="Check logs"),
     *                     @OA\Property(property="isCompleted", type="boolean", example=false)
     *                 ),
     *                 example={
     *                     {"title": "Configure environment", "isCompleted": true},
     *                     {"title": "Install dependencies", "isCompleted": false}
     *                 }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Task created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="category", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="importance", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="subTasks", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="message", type="string", nullable=true),
     *             @OA\Property(property="dueAt", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="position", type="integer"),
     *             @OA\Property(property="createdAt", type="string"),
     *             @OA\Property(property="updatedAt", type="string"),
     *             @OA\Property(property="deletedAt", type="string", format="date-time", nullable=true)
     *         )
     *     )
     * )
     */
    public function create(Request $request, EntityManagerInterface $em, \Symfony\Component\Validator\Validator\ValidatorInterface $validator): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?: [];

        $titleInput = isset($data['title']) && is_scalar($data['title']) ? (string)$data['title'] : '';
        if (trim($titleInput) === '') {
            return $this->json(['error' => 'El título es obligatorio'], 400);
        }

        $task = new KanbanTask();
        $task->setTitle($titleInput);
        
        $categoryInput = $data['category'] ?? [];
        $task->setCategory(is_array($categoryInput) ? $categoryInput : [$categoryInput]);

        $task->setImportance(isset($data['importance']) && is_scalar($data['importance']) ? (string)$data['importance'] : 'mediana');
        $task->setStatus(isset($data['status']) && is_scalar($data['status']) ? (string)$data['status'] : KanbanTask::STATUS_BACKLOG);
        $task->setSubTasks($data['subTasks'] ?? []);
        $task->setMessage(isset($data['message']) && is_scalar($data['message']) ? (string)$data['message'] : null);
        $task->setPosition(isset($data['position']) && is_numeric($data['position']) ? (int)$data['position'] : 0);
        $task->setOwner($user);

        if (!empty($data['dueAt'])) {
            try {
                $task->setDueAt(new \DateTime($data['dueAt']));
            } catch (\Exception $e) {
                return $this->json(['error' => 'Formato de fecha de vencimiento (dueAt) inválido'], 400);
            }
        }

        $errors = $validator->validate($task);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        $em->persist($task);
        $em->flush();

        return $this->json($task, 201, [], ['groups' => 'kanban:read']);
    }

    /**
     * Update an active task for the authenticated user.
     * 
     * @Route("/{id}", name="update", methods={"PUT"}, requirements={"id"="\d+"})
     * 
     * @OA\Put(
     *     summary="Updates a task in the user's personal board",
     *     tags={"Kanban Board"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Fix login bug"),
     *             @OA\Property(property="category", type="array", @OA\Items(type="string"), example={"Development", "Bug"}),
     *             @OA\Property(property="importance", type="string", example="alta"),
     *             @OA\Property(property="status", type="string", example="Por Hacer"),
     *             @OA\Property(property="message", type="string", example="Updated details for the task.", nullable=true),
     *             @OA\Property(property="dueAt", type="string", format="date-time", example="2026-05-30T12:00:00Z", nullable=true),
     *             @OA\Property(property="position", type="integer", example=1),
     *             @OA\Property(
     *                 property="subTasks",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="title", type="string", example="Check logs"),
     *                     @OA\Property(property="isCompleted", type="boolean", example=false)
     *                 ),
     *                 example={
     *                     {"title": "Analyze error logs", "isCompleted": true},
     *                     {"title": "Correct redirection", "isCompleted": false}
     *                 }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="category", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="importance", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="subTasks", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="message", type="string", nullable=true),
     *             @OA\Property(property="dueAt", type="string", format="date-time", nullable=true),
     *             @OA\Property(property="position", type="integer"),
     *             @OA\Property(property="createdAt", type="string"),
     *             @OA\Property(property="updatedAt", type="string"),
     *             @OA\Property(property="deletedAt", type="string", format="date-time", nullable=true)
     *         )
     *     )
     * )
     */
    public function update(int $id, Request $request, KanbanTaskRepository $repository, EntityManagerInterface $em, \Symfony\Component\Validator\Validator\ValidatorInterface $validator): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Find task owned by this user (including soft-deleted ones)
        $task = $repository->findOneBy(['id' => $id, 'owner' => $user]);

        if (!$task) {
            return $this->json(['error' => 'Tarea no encontrada o acceso denegado'], 404);
        }

        $data = json_decode($request->getContent(), true) ?: [];

        if (array_key_exists('title', $data)) {
            $task->setTitle(is_scalar($data['title']) ? (string)$data['title'] : '');
        }
        if (array_key_exists('category', $data)) {
            $categoryInput = $data['category'] ?? [];
            $task->setCategory(is_array($categoryInput) ? $categoryInput : [$categoryInput]);
        }
        if (array_key_exists('importance', $data)) {
            $task->setImportance(is_scalar($data['importance']) ? (string)$data['importance'] : '');
        }
        if (array_key_exists('status', $data)) {
            $task->setStatus(is_scalar($data['status']) ? (string)$data['status'] : '');
        }
        if (array_key_exists('subTasks', $data)) {
            $task->setSubTasks(is_array($data['subTasks']) ? $data['subTasks'] : []);
        }
        if (array_key_exists('message', $data)) {
            $task->setMessage(is_scalar($data['message']) ? (string)$data['message'] : null);
        }
        if (array_key_exists('position', $data)) {
            $task->setPosition(is_numeric($data['position']) ? (int)$data['position'] : 0);
        }

        if (array_key_exists('dueAt', $data)) {
            if (empty($data['dueAt'])) {
                $task->setDueAt(null);
            } else {
                try {
                    $task->setDueAt(new \DateTime($data['dueAt']));
                } catch (\Exception $e) {
                    return $this->json(['error' => 'Formato de fecha de vencimiento (dueAt) inválido'], 400);
                }
            }
        }

        $errors = $validator->validate($task);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        $em->flush();

        return $this->json($task, 200, [], ['groups' => 'kanban:read']);
    }

    /**
     * Soft-delete a user's task.
     * 
     * @Route("/{id}", name="delete", methods={"DELETE"}, requirements={"id"="\d+"})
     * 
     * @OA\Delete(
     *     summary="Soft-deletes a task from the user's personal board by setting its deletedAt timestamp",
     *     tags={"Kanban Board"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Task soft-deleted successfully")
     * )
     */
    public function delete(int $id, KanbanTaskRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Find only active (non-soft-deleted) tasks owned by this user
        $task = $repository->findOneBy(['id' => $id, 'owner' => $user, 'deletedAt' => null]);

        if (!$task) {
            return $this->json(['error' => 'Tarea no encontrada o acceso denegado'], 404);
        }

        // Soft delete by setting deletedAt timestamp
        $task->setDeletedAt(new \DateTime());
        $em->flush();

        return $this->json(['status' => 'success', 'message' => 'Tarea eliminada exitosamente']);
    }

    /**
     * Reorder user's tasks.
     * 
     * @Route("/reorder", name="reorder", methods={"PUT"})
     * 
     * @OA\Put(
     *     summary="Reorders multiple active tasks on the user's board for custom sorting",
     *     tags={"Kanban Board"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="taskIds", type="array", @OA\Items(type="integer"), example={12, 15, 8})
     *         )
     *     ),
     *     @OA\Response(response=200, description="Tasks reordered successfully")
     * )
     */
    public function reorder(Request $request, KanbanTaskRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?: [];

        $taskIds = $data['taskIds'] ?? [];
        if (!is_array($taskIds) || empty($taskIds)) {
            return $this->json(['error' => 'Lista de IDs de tareas inválida'], 400);
        }

        // Fetch all active matching tasks for this user to verify ownership
        $tasks = $repository->findBy(['id' => $taskIds, 'owner' => $user, 'deletedAt' => null]);
        $taskMap = [];
        foreach ($tasks as $task) {
            $taskMap[$task->getId()] = $task;
        }

        // Reorder tasks in batch by updating their sequential position values
        foreach ($taskIds as $position => $id) {
            if (isset($taskMap[$id])) {
                $taskMap[$id]->setPosition($position);
            }
        }

        $em->flush();

        return $this->json(['status' => 'success', 'message' => 'Tablero reordenado exitosamente']);
    }
    
    /**
     * Toggle active status of a personal task.
     * 
     * @Route("/{id}/toggle-active", name="toggle_active", methods={"POST"}, requirements={"id"="\d+"})
     * 
     * @OA\Post(
     *     summary="Toggles the active state of a user's task between active and soft-deleted",
     *     tags={"Kanban Board"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Status toggled successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Tarea activada"),
     *             @OA\Property(property="isActive", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=404, description="Task not found or access denied")
     * )
     */
    public function toggleActive(int $id, KanbanTaskRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Enforce ownership immediately upon database lookup
        $task = $repository->findOneBy(['id' => $id, 'owner' => $user]);

        if (!$task) {
            return $this->json(['error' => 'Tarea no encontrada o acceso denegado'], 404);
        }

        // State check logic (using getDeletedAt because your entity uses that field)
        $currentlyActive = ($task->getDeletedAt() === null);

        if ($currentlyActive) {
            $task->setDeletedAt(new \DateTime());
        } else {
            $task->setDeletedAt(null);
        }

        $em->flush();

        // Recalculate state for the final response match
        $newActiveState = ($task->getDeletedAt() === null);

        return $this->json([
            'message' => $newActiveState ? 'Tarea activada' : 'Tarea desactivada',
            'isActive' => $newActiveState
        ]);
    }
}
