<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\ChatMessage;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use OpenApi\Annotations as OA;

/**
 * @Route("/api/chat", name="api_chat_")
 */
class ChatController extends AbstractController
{
    /**
     * Send a real-time chat message.
     * This endpoint receives a message from an authenticated user (via Lexik JWT)
     * and publishes it to the Mercure Hub for other connected users to receive.
     * 
     * @Route("/send", name="send_message", methods={"POST"})
     * 
     * @OA\Post(
     *     summary="Sends a chat message and publishes it via Mercure",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="category", type="string", example="admin", description="Chat category (e.g., admin, general)"),
     *             @OA\Property(property="topic", type="string", example="welcome", description="Channel/topic name"),
     *             @OA\Property(property="message", type="string", example="¡Hola!")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Message published successfully"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized user"
     *     )
     * )
     */
    public function sendMessage(Request $request, HubInterface $hub, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Decode incoming JSON
        $data = json_decode($request->getContent(), true);

        $category = $data['category'] ?? 'general';
        $topicName = $data['topic'] ?? 'main';
        $fullTopic = $category . '/' . $topicName;

        $message = $data['message'] ?? '';

        if (empty($message)) {
            return $this->json(['error' => 'El mensaje no puede estar vacío'], 400);
        }

        // 1. Save to Database
        $chatMessage = new ChatMessage();
        $chatMessage->setContent($message);
        $chatMessage->setCategory($category);
        $chatMessage->setTopic($topicName);
        $chatMessage->setSender($user);

        $em->persist($chatMessage);
        $em->flush();

        // 2. Prepare payload for the frontend
        $payload = [
            'id' => $chatMessage->getId(),
            'senderId' => $user->getId(),
            'senderName' => $user->getDisplayName(),
            'category' => $category,
            'topic' => $topicName,
            'message' => $message,
            'timestamp' => $chatMessage->getCreatedAt()->format('c')
        ];

        // 3. Create Mercure Update
        $update = new Update(
            $fullTopic,
            json_encode($payload),
            true
        );

        // 4. Publish message to the Hub
        $hub->publish($update);

        return $this->json([
            'status' => 'success',
            'message' => 'Mensaje enviado a la sala ' . $topicName
        ]);
    }

    /**
     * Get message history for a specific room using Category and Topic.
     * 
     * @Route("/{category}/{topic}", name="get_history", methods={"GET"})
     * 
     * @OA\Get(
     *     summary="Retrieves the last 50 messages from a specific category and topic",
     *     @OA\Parameter(
     *         name="category",
     *         in="path",
     *         description="e.g., admin, general",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="topic",
     *         in="path",
     *         description="e.g., welcome, support, alerts",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of messages",
     *         @OA\JsonContent(type="array", @OA\Items(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="senderId", type="integer"),
     *             @OA\Property(property="senderName", type="string"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="timestamp", type="string"),
     *             @OA\Property(property="updatedAt", type="string", nullable=true)
     *         ))
     *     )
     * )
     */
    public function getHistory(string $category, string $topic, ChatMessageRepository $repository): JsonResponse
    {
        // Find the last 50 messages filtering by both fields in ascending order
        $messages = $repository->findBy(
            ['category' => $category, 'topic' => $topic],
            ['createdAt' => 'ASC'],
            50
        );

        // Return messages
        $data = [];
        foreach ($messages as $msg) {
            $data[] = [
                'id' => $msg->getId(),
                'senderId' => $msg->getSender()->getId(),
                'senderName' => $msg->getSender()->getDisplayName(),
                'message' => $msg->getContent(),
                'timestamp' => $msg->getCreatedAt()->format('c'),
                'updatedAt' => $msg->getUpdatedAt() ? $msg->getUpdatedAt()->format('c') : null
            ];
        }

        return $this->json($data);
    }

    /**
     * Edit a chat message.
     * Only the author can edit it within a 30-minute window.
     * 
     * @Route("/{id}", name="update_message", methods={"PUT"})
     * 
     * @OA\Put(
     *     summary="Edits a chat message and notifies via Mercure",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the message to edit",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Mensaje corregido")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Message edited successfully"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Permission denied or time window expired"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Message not found"
     *     )
     * )
     */
    public function updateMessage(int $id, Request $request, ChatMessageRepository $repository, EntityManagerInterface $em, HubInterface $hub): JsonResponse
    {
        $chatMessage = $repository->find($id);

        if (!$chatMessage) {
            return $this->json(['error' => 'Mensaje no encontrado'], 404);
        }

        /** @var User $user */
        $user = $this->getUser();

        // 1. Ownership Verification (Only the author)
        if ($chatMessage->getSender() !== $user) {
            return $this->json(['error' => 'No tienes permiso para editar este mensaje'], 403);
        }

        // 2. Time Window (30 minutes)
        $now = new \DateTime();
        $createdAt = $chatMessage->getCreatedAt();
        $diff = $now->getTimestamp() - $createdAt->getTimestamp();

        if ($diff > (30 * 60)) {
            return $this->json(['error' => 'El tiempo para editar este mensaje ha expirado'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $newMessage = $data['message'] ?? '';

        if (empty($newMessage)) {
            return $this->json(['error' => 'El mensaje no puede estar vacío'], 400);
        }

        // 3. Update Entity
        $chatMessage->setContent($newMessage);
        $chatMessage->setUpdatedAt(new \DateTime());

        $em->flush();

        // 4. Notify via Mercure ("Edited" Flag)
        $fullTopic = $chatMessage->getCategory() . '/' . $chatMessage->getTopic();
        $payload = [
            'type' => 'message_updated',
            'id' => $id,
            'message' => $newMessage,
            'updatedAt' => $chatMessage->getUpdatedAt()->format('c')
        ];

        $update = new Update(
            $fullTopic,
            json_encode($payload),
            true
        );
        $hub->publish($update);

        return $this->json([
            'status' => 'success',
            'message' => 'Mensaje actualizado correctamente',
            'updatedAt' => $chatMessage->getUpdatedAt()->format('c')
        ]);
    }

    /**
     * Delete a chat message.
     * Only the author or an administrator can delete it.
     * 
     * @Route("/{id}", name="delete_message", methods={"DELETE"})
     * 
     * @OA\Delete(
     *     summary="Deletes a chat message and notifies via Mercure",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the message to delete",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Message deleted successfully"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="You do not have permission to delete this message"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Message not found"
     *     )
     * )
     */
    public function deleteMessage(int $id, ChatMessageRepository $repository, EntityManagerInterface $em, HubInterface $hub): JsonResponse
    {
        $chatMessage = $repository->find($id);

        if (!$chatMessage) {
            return $this->json(['error' => 'Mensaje no encontrado'], 404);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Security: Only the author or an admin can delete
        if ($chatMessage->getSender() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'No tienes permiso para eliminar este mensaje'], 403);
        }

        // Prepare data for Mercure before deleting from DB
        $category = $chatMessage->getCategory();
        $topicName = $chatMessage->getTopic();
        $fullTopic = $category . '/' . $topicName;

        $payload = [
            'type' => 'message_deleted',
            'id' => $id
        ];

        // 1. Delete from Database
        $em->remove($chatMessage);
        $em->flush();

        // 2. Notify Mercure Hub
        $update = new Update(
            $fullTopic,
            json_encode($payload),
            true
        );
        $hub->publish($update);

        return $this->json([
            'status' => 'success',
            'message' => 'Mensaje eliminado correctamente'
        ]);
    }
}
