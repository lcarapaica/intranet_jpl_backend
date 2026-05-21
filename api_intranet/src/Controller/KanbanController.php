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
 * @Route("/api/board", name="api_board_")
 */
class KanbanController extends AbstractController
{
    /**
     * Get all tasks for the authenticated user.
     * 
     * @Route("", name="list", methods={"GET"})
     * 
     * @OA\Get(
     *     summary="Retrieves all tasks for the current user's personal board",
     *     tags={"Tablero Kanban"},
     *     @OA\Response(
     *         response=200,
     *         description="List of tasks",
     *         @OA\JsonContent(type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="category", type="string"),
     *             @OA\Property(property="importance", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="subTasks", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="createdAt", type="string"),
     *             @OA\Property(property="updatedAt", type="string")
     *         ))
     *     )
     * )
     */
    public function list(KanbanTaskRepository $repository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Retrieve only tasks owned by the authenticated user
        $tasks = $repository->findBy(['owner' => $user], ['createdAt' => 'DESC']);

        // Return the tasks serialized with the kanban
        return $this->json($tasks, 200, [], ['groups' => 'kanban:read']);
    }

    /**
     * Create a new task for the authenticated user.
     * 
     * @Route("", name="create", methods={"POST"})
     * 
     * @OA\Post(
     *     summary="Creates a new task in the user's personal board",
     *     tags={"Tablero Kanban"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Fix login bug"),
     *             @OA\Property(property="category", type="string", example="Development"),
     *             @OA\Property(property="importance", type="string", example="alta"),
     *             @OA\Property(property="status", type="string", example="Por Hacer"),
     *             @OA\Property(
     *                 property="subTasks",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="title", type="string", example="Check logs"),
     *                     @OA\Property(property="isCompleted", type="boolean", example=false)
     *                 ),
     *                 example={
     *                     {"title": "Configurar entorno", "isCompleted": true},
     *                     {"title": "Instalar dependencias", "isCompleted": false}
     *                 }
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Task created successfully")
     * )
     */
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true);

        // Title validation check=
        if (empty($data['title'])) {
            return $this->json(['error' => 'El título es obligatorio'], 400);
        }

        // Initialize task entity and populate with defaults if keys are missing
        $task = new KanbanTask();
        $task->setTitle($data['title']);
        $task->setCategory($data['category'] ?? 'General');
        $task->setImportance($data['importance'] ?? 'mediana');
        $task->setStatus($data['status'] ?? KanbanTask::STATUS_BACKLOG);
        $task->setSubTasks($data['subTasks'] ?? []);
        $task->setOwner($user);

        $em->persist($task);
        $em->flush();

        return $this->json($task, 201, [], ['groups' => 'kanban:read']);
    }

    /**
     * Update a user's task.
     * 
     * @Route("/{id}", name="update", methods={"PUT"})
     * 
     * @OA\Put(
     *     summary="Updates a task in the user's personal board",
     *     tags={"Tablero Kanban"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Fix login bug"),
     *             @OA\Property(property="category", type="string", example="Development"),
     *             @OA\Property(property="importance", type="string", example="alta"),
     *             @OA\Property(property="status", type="string", example="Por Hacer"),
     *             @OA\Property(
     *                 property="subTasks",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="title", type="string", example="Check logs"),
     *                     @OA\Property(property="isCompleted", type="boolean", example=false)
     *                 ),
     *                 example={
     *                     {"title": "Analizar logs de error", "isCompleted": true},
     *                     {"title": "Corregir redirección", "isCompleted": false}
     *                 }
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Task updated successfully")
     * )
     */
    public function update(int $id, Request $request, KanbanTaskRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        // Ensures only the user can edit their tasks
        $user = $this->getUser();
        $task = $repository->findOneBy(['id' => $id, 'owner' => $user]);

        if (!$task) {
            return $this->json(['error' => 'Tarea no encontrada o acceso denegado'], 404);
        }

        $data = json_decode($request->getContent(), true);

        // Perform partial updates only for properties present in the request body
        if (isset($data['title'])) $task->setTitle($data['title']);
        if (isset($data['category'])) $task->setCategory($data['category']);
        if (isset($data['importance'])) $task->setImportance($data['importance']);
        if (isset($data['status'])) $task->setStatus($data['status']);
        if (isset($data['subTasks'])) $task->setSubTasks($data['subTasks']);

        $em->flush();

        return $this->json($task, 200, [], ['groups' => 'kanban:read']);
    }

    /**
     * Delete a user's task.
     * 
     * @Route("/{id}", name="delete", methods={"DELETE"})
     * 
     * @OA\Delete(
     *     summary="Deletes a task from the user's personal board",
     *     tags={"Tablero Kanban"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Task deleted successfully")
     * )
     */
    public function delete(int $id, KanbanTaskRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Ensures users can only delete their own tasks
        $task = $repository->findOneBy(['id' => $id, 'owner' => $user]);

        if (!$task) {
            return $this->json(['error' => 'Tarea no encontrada o acceso denegado'], 404);
        }

        $em->remove($task);
        $em->flush();

        return $this->json(['status' => 'success', 'message' => 'Tarea eliminada correctamente']);
    }
}
