<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\ChatMessage;
use App\Service\AuditLogger;
use App\Repository\UserRepository;
use App\Repository\ConversationRepository;
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
     * Creates a new conversation (private or group).
     * 
     * @Route("/conversations", name="create_conversation", methods={"POST"})
     * 
     * @OA\Post(
     *     path="/api/chat/conversations",
     *     summary="Create a new conversation (private or group)",
     *     tags={"Mensajeria"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="type", type="string", example="private", description="Tipo de chat: private o group"),
     *             @OA\Property(property="name", type="string", nullable=true, example="General IT", description="Nombre del chat grupal"),
     *             @OA\Property(property="participantIds", type="array", @OA\Items(type="integer"), example={2}, description="Lista de IDs de usuarios a incluir en la conversación")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Conversación creada exitosamente"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="La conversación ya existía, devuelve el ID de la conversación existente"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Petición incorrecta (p.ej., falta el ID del destinatario para chat privado)"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Destinatario no encontrado"
     *     )
     * )
     */
    public function createConversation(Request $request, UserRepository $userRepository, ConversationRepository $convRepo, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser(); // Obtains the current user
        $data = json_decode($request->getContent(), true); // Retrieves the name, type and participants of the conversation.

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['error' => 'Formato JSON inválido'], 400);
        }

        // Extract and filter the list of participant IDs from the request data.
        $participantIds = $data['participantIds'] ?? [];
        $type = $data['type'] ?? (count($participantIds) > 1 ? 'group' : 'private'); // Defaults to a private chat if 2 participants, group for more.
        $name = $data['name'] ?? null; // Group name is optional

        if ($type === 'private') { // Check to ensure atleast one participant is added
            if (empty($participantIds)) {
                return $this->json(['error' => 'El ID del destinatario es obligatorio para un chat privado'], 400);
            }
            if (count($participantIds) !== 1) { // Check to ensure private chats can't have more than 2 participants.
                return $this->json(['error' => 'Una conversación privada debe tener exactamente un destinatario'], 400);
            }
            $recipientId = $participantIds[0]; //Sets the recipient ID to the sole participant for recovery

            if ($recipientId === $currentUser->getId()) { // Check to prevent private conversation with self
                return $this->json(['error' => 'No puedes crear una conversación privada contigo mismo'], 400);
            }

            // Check if conversation already exists
            $existing = $convRepo->findPrivateConversationBetweenUsers($currentUser->getId(), $recipientId);
            if ($existing) {
                return $this->json(['id' => $existing->getId(), 'message' => 'La conversación ya existe'], 200);
            }

            // Verify that the recipient exists in the database
            $recipient = $userRepository->find($recipientId);
            if (!$recipient) {
                return $this->json(['error' => 'Destinatario no encontrado'], 404);
            }

            // Initialize a new private conversation and create the participation records for both users.
            $conversation = new Conversation();
            $conversation->setType('private');
            $conversation->setName(null);

            $p1 = new ConversationParticipant();
            $p1->setUser($currentUser);
            $p1->setRole('member');
            $conversation->addParticipant($p1);

            $p2 = new ConversationParticipant();
            $p2->setUser($recipient);
            $p2->setRole('member');
            $conversation->addParticipant($p2);

            // Persist the newly created private conversation and its participant relationships.
            $em->persist($conversation);
            $em->persist($p1);
            $em->persist($p2);
        } else {
            // Group chat
            if (empty($name) || trim($name) === '') {
                return $this->json(['error' => 'El nombre es obligatorio para un chat grupal'], 400);
            }
            $conversation = new Conversation();
            $conversation->setType('group');
            $conversation->setName(trim($name));

            $pSelf = new ConversationParticipant();
            $pSelf->setUser($currentUser);
            $pSelf->setRole('admin');
            $conversation->addParticipant($pSelf);
            $em->persist($pSelf);

            // Iterate through the provided participant IDs and add active users to the group.
            foreach ($participantIds as $pId) {
                $user = $userRepository->find($pId);
                if ($user) {
                    $p = new ConversationParticipant();
                    $p->setUser($user);
                    $p->setRole('member');
                    $conversation->addParticipant($p);
                    $em->persist($p);
                }
            }
            $em->persist($conversation);
        }

        // Save all changes, persisting the new conversation and participants in the database.
        $em->flush();

        return $this->json([
            'id' => $conversation->getId(),
            'type' => $conversation->getType(),
            'name' => $conversation->getName()
        ], 201);
    }

    /**
     * Lists all conversations for the authenticated user.
     * 
     * @Route("/conversations", name="list_conversations", methods={"GET"})
     * 
     * @OA\Get(
     *     path="/api/chat/conversations",
     *     summary="List all conversations for the authenticated user",
     *     tags={"Mensajeria"},
     *     @OA\Response(
     *         response=200,
     *         description="Lista de conversaciones obtenida exitosamente"
     *     )
     * )
     */
    public function listConversations(ConversationRepository $convRepo): JsonResponse
    {
        // Retrieve the currently authenticated user from the security context.
        /** @var User $user */
        $user = $this->getUser();

        // Retrieve all conversations that the current user is participating in.
        $conversations = $convRepo->findAllForUser($user->getId());

        // Build the response payload containing details for each conversation.
        $data = [];
        foreach ($conversations as $conv) {
            $participants = [];
            // Gather participant details, excluding the current user in private chats for cleaner response.
            foreach ($conv->getParticipants() as $p) {
                if ($p->getUser()->getId() !== $user->getId() || $conv->getType() === 'group') {
                    $participants[] = [
                        'id' => $p->getUser()->getId(),
                        'name' => $p->getUser()->getDisplayName()
                    ];
                }
            }

            $data[] = [
                'id' => $conv->getId(),
                'type' => $conv->getType(),
                'name' => $conv->getName(),
                'lastUpdatedAt' => $conv->getUpdatedAt()->format('c'),
                'participants' => $participants
            ];
        }

        // Return the formatted list of conversations as a JSON response.
        return $this->json($data);
    }

    /**
     * Get details of a specific conversation including participant details.
     * 
     * @Route("/conversations/{id}", name="get_conversation", methods={"GET"})
     * 
     * @OA\Get(
     *     path="/api/chat/conversations/{id}",
     *     summary="Obtain the information of a specific conversation and its members",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la conversación",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Información de la conversación obtenida exitosamente"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="El usuario no participa en esta conversación"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Conversación no encontrada"
     *     )
     * )
     */
    public function getConversation(int $id, ConversationRepository $convRepo): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Retrieve the conversation
        $conversation = $convRepo->find($id);

        if (!$conversation) {
            return $this->json(['error' => 'Conversación no encontrada'], 404);
        }

        // Verify participation using optimized repository helper
        if (!$convRepo->hasParticipant($id, $currentUser->getId())) {
            return $this->json(['error' => 'Acceso denegado. No eres participante de esta conversación.'], 403);
        }

        // Build participants details list
        $participantsData = [];
        foreach ($conversation->getParticipants() as $p) {
            $participantsData[] = [
                'id' => $p->getUser()->getId(),
                'name' => $p->getUser()->getDisplayName(),
                'email' => $p->getUser()->getEmail(),
                'role' => $p->getRole(),
                'joinedAt' => $p->getJoinedAt()->format('c')
            ];
        }

        // Build and return payload
        return $this->json([
            'id' => $conversation->getId(),
            'name' => $conversation->getName(),
            'type' => $conversation->getType(),
            'createdAt' => $conversation->getCreatedAt()->format('c'),
            'updatedAt' => $conversation->getUpdatedAt()->format('c'),
            'participants' => $participantsData
        ]);
    }

    /**
     * Send a real-time message to a conversation.
     * 
     * @Route("/conversations/{id}/messages", name="send_message", methods={"POST"})
     * 
     * @OA\Post(
     *     path="/api/chat/conversations/{id}/messages",
     *     summary="Send a real-time message to a conversation",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la conversación",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Hola grupo, ¿cómo están?")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Mensaje enviado y publicado mediante Mercure"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="El contenido del mensaje no puede estar vacío"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="El usuario no participa en esta conversación"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Conversación no encontrada"
     *     )
     * )
     */
    public function sendMessage(int $id, Request $request, HubInterface $hub, EntityManagerInterface $em, ConversationRepository $convRepo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Find the conversation by its ID, returning a 404 error if not found.
        $conversation = $convRepo->find($id);

        if (!$conversation) {
            return $this->json(['error' => 'Conversación no encontrada'], 404);
        }

        // Verify participation using optimized repository helper
        if (!$convRepo->hasParticipant($id, $user->getId())) {
            return $this->json(['error' => 'No eres un participante en esta conversación'], 403);
        }

        // Decode and validate the incoming request body content.
        $data = json_decode($request->getContent(), true);
        $content = $data['message'] ?? '';

        if (empty($content)) {
            return $this->json(['error' => 'El contenido del mensaje no puede estar vacío'], 400);
        }

        // Create a new message instance, set its fields, and update the conversation timestamp.
        $chatMessage = new ChatMessage();
        $chatMessage->setContent($content);
        $chatMessage->setSender($user);
        $chatMessage->setConversation($conversation);

        $conversation->setUpdatedAt(new \DateTime());

        // Restore/unhide conversation for any participant who deleted/hid it previously
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getDeletedAt() !== null) {
                $p->setDeletedAt(null);
            }
        }

        // Save the new message and update conversation participants in the database.
        $em->persist($chatMessage);
        $em->flush();

        // Construct the message payload to be sent over Mercure and returned in the HTTP response.
        $payload = [
            'id' => $chatMessage->getId(),
            'conversationId' => $conversation->getId(),
            'senderId' => $user->getId(),
            'senderName' => $user->getDisplayName(),
            'message' => $content,
            'timestamp' => $chatMessage->getCreatedAt()->format('c')
        ];

        // Publish the real-time update to the Mercure hub for the active conversation topic.
        $update = new Update(
            "conversations/{$conversation->getId()}",
            json_encode($payload),
            true
        );

        try {
            $hub->publish($update);
        } catch (\Exception $e) {
            error_log('Mercure publish failed: ' . $e->getMessage());
        }

        return $this->json($payload, 201);
    }

    /**
     * Get message history for a conversation.
     * 
     * @Route("/conversations/{id}/messages", name="get_history", methods={"GET"})
     * 
     * @OA\Get(
     *     path="/api/chat/conversations/{id}/messages",
     *     summary="Get the message history of a conversation",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la conversación",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Número de mensajes a obtener (por defecto 50, máximo 200)",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="beforeId",
     *         in="query",
     *         description="ID de mensaje de corte para paginación hacia atrás (obtiene mensajes anteriores a este ID)",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Palabra o frase para filtrar los mensajes de esta conversación",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de mensajes de la conversación en orden cronológico"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Acceso denegado (el usuario no es participante ni administrador)"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Conversación no encontrada"
     *     )
     * )
     */
    public function getHistory(int $id, Request $request, ChatMessageRepository $repository, ConversationRepository $convRepo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Find the conversation by ID, or return 404 if it does not exist.
        $conversation = $convRepo->find($id);

        if (!$conversation) {
            return $this->json(['error' => 'Conversación no encontrada'], 404);
        }

        // Verify participation using optimized repository helper
        if (!$convRepo->hasParticipant($id, $user->getId())) {
            return $this->json(['error' => 'Acceso denegado'], 403);
        }
        // Establishes limits on how many messages can be retrieved at once
        $limit = $request->query->getInt('limit', 50);
        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $beforeId = $request->query->get('beforeId');
        $beforeId = $beforeId !== null ? (int)$beforeId : null;
        $search = $request->query->get('search');

        // Retrieve the latest active messages, capped at limit.
        $messages = $repository->findMessagesForConversation($id, $limit, $beforeId, $search);

        // Format history chronologically (oldest of the batch first) by reversing the retrieved DESC array
        $messages = array_reverse($messages);

        // Format the history payload including message IDs, senders, text, and timestamps.
        $data = [];
        foreach ($messages as $msg) {
            $data[] = [
                'id' => $msg->getId(),
                'senderId' => $msg->getSender()->getId(),
                'senderName' => $msg->getSender()->getDisplayName(),
                'message' => $msg->getContent(),
                'timestamp' => $msg->getUpdatedAt() ? $msg->getUpdatedAt()->format('c') : $msg->getCreatedAt()->format('c'),
                'updatedAt' => $msg->getUpdatedAt() ? $msg->getUpdatedAt()->format('c') : null
            ];
        }

        return $this->json($data);
    }

    /**
     * Edit a chat message.
     * 
     * @Route("/messages/{id}", name="update_message", methods={"PUT"})
     * 
     * @OA\Put(
     *     path="/api/chat/messages/{id}",
     *     summary="Edit a chat message within a 30-minute window",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID del mensaje a editar",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Mensaje editado y corregido")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Mensaje editado correctamente"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="El contenido del mensaje no puede estar vacío"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="No autorizado (no eres el emisor) o el periodo de edición de 30 minutos ha expirado"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Mensaje no encontrado"
     *     )
     * )
     */
    public function updateMessage(int $id, Request $request, ChatMessageRepository $repository, EntityManagerInterface $em, HubInterface $hub): JsonResponse
    {
        // Locate the target message by its ID, returning 404 if not found.
        $chatMessage = $repository->find($id);

        if (!$chatMessage) {
            return $this->json(['error' => 'Mensaje no encontrado'], 404);
        }

        // Block modifying soft-deleted messages
        if ($chatMessage->getDeletedAt() !== null) {
            return $this->json(['error' => 'No se puede modificar un mensaje que ha sido eliminado lógicamente.'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Ensure only the sender of the message can edit it
        if ($chatMessage->getSender() !== $user) {
            return $this->json(['error' => 'No autorizado'], 403);
        }

        // Verify current user is a participant of the conversation
        $conversation = $chatMessage->getConversation();
        $isParticipant = false;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getUser()->getId() === $user->getId()) {
                $isParticipant = true;
                break;
            }
        }

        if (!$isParticipant) {
            return $this->json(['error' => 'No eres parte de la conversación'], 403);
        }

        // Enforce the business rule restricting message edits to a 30-minute window.
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $chatMessage->getCreatedAt()->getTimestamp();
        if ($diff > (30 * 60)) {
            return $this->json(['error' => 'La ventana de tiempo para editar ha expirado'], 403);
        }

        // Extract and validate the updated message content from the request.
        $data = json_decode($request->getContent(), true);
        $newMessage = $data['message'] ?? '';

        if (empty($newMessage)) {
            return $this->json(['error' => 'El contenido del mensaje no puede estar vacío'], 400);
        }

        // Apply the edit, set the updated timestamp, and save the changes.
        $chatMessage->setContent($newMessage);
        $chatMessage->setUpdatedAt(new \DateTime());
        $em->flush();

        // Broadcast the update notification to other conversation participants via Mercure.
        $payload = [
            'type' => 'message_updated',
            'id' => $id,
            'message' => $newMessage,
            'updatedAt' => $chatMessage->getUpdatedAt()->format('c')
        ];

        $update = new Update(
            "conversations/{$chatMessage->getConversation()->getId()}",
            json_encode($payload),
            true
        );

        try {
            $hub->publish($update);
        } catch (\Exception $e) {
            error_log('Mercure update failed: ' . $e->getMessage());
        }

        return $this->json(['status' => 'success']);
    }

    /**
     * Delete a chat message.
     * 
     * @Route("/messages/{id}", name="delete_message", methods={"DELETE"})
     * 
     * @OA\Delete(
     *     path="/api/chat/messages/{id}",
     *     summary="Delete a chat message (soft delete)",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID del mensaje a eliminar",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Mensaje eliminado correctamente"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="No autorizado (no eres el emisor ni un administrador)"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Mensaje no encontrado"
     *     )
     * )
     */
    public function deleteMessage(int $id, ChatMessageRepository $repository, EntityManagerInterface $em, HubInterface $hub): JsonResponse
    {
        // Locate the target message in the database, returning 404 if not found.
        $chatMessage = $repository->find($id);

        if (!$chatMessage) {
            return $this->json(['error' => 'Mensaje no encontrado'], 404);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Determine if the current user is an active participant in this conversation.
        $conversation = $chatMessage->getConversation();
        $isParticipant = false;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getUser()->getId() === $user->getId()) {
                $isParticipant = true;
                break;
            }
        }

        // Validate authorization: the user must be either the sender or a system administrator.
        $isSender = ($chatMessage->getSender() === $user);
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if (!$isSender && !$isAdmin) {
            return $this->json(['error' => 'No autorizado'], 403);
        }

        // Validate active participation: authorized users must still be part of the conversation.
        if (!$isParticipant) {
            return $this->json(['error' => 'No eres parte de la conversación'], 403);
        }

        // Perform a soft delete by storing the deletion timestamp and persisting it.
        $payload = [
            'type' => 'message_deleted',
            'id' => $id
        ];

        $chatMessage->setDeletedAt(new \DateTime());
        $em->flush();

        // Broadcast the message deletion notification to other participants via Mercure.
        try {
            $update = new Update(
                "conversations/{$chatMessage->getConversation()->getId()}",
                json_encode($payload),
                true
            );
            $hub->publish($update);
        } catch (\Exception $e) {
            error_log('Mercure delete failed: ' . $e->getMessage());
        }

        return $this->json(['status' => 'success']);
    }

    /**
     * Delete/Hide a conversation for the authenticated user.
     * 
     * @Route("/conversations/{id}", name="delete_conversation", methods={"DELETE"})
     * 
     * @OA\Delete(
     *     path="/api/chat/conversations/{id}",
     *     summary="Hide/delete a conversation for the authenticated user",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la conversación a ocultar",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Conversación oculta exitosamente"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="No autorizado (no eres participante)"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Conversación no encontrada"
     *     )
     * )
     */
    public function deleteConversation(int $id, ConversationRepository $convRepo, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Retrieve the conversation, ensuring it exists before hiding/deleting.
        $conversation = $convRepo->find($id);

        if (!$conversation) {
            return $this->json(['error' => 'Conversación no encontrada'], 404);
        }

        // Verify that the current user is part of the conversation.
        $participant = null;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getUser()->getId() === $user->getId()) {
                $participant = $p;
                break;
            }
        }

        if (!$participant) {
            return $this->json(['error' => 'No eres un participante en esta conversación'], 403);
        }

        // Perform a soft delete of the participation record, effectively hiding it from their view.
        $participant->setDeletedAt(new \DateTime());
        $em->flush();

        return $this->json(['status' => 'success', 'message' => 'Conversación oculta exitosamente']);
    }

    /**
     * Add one or multiple participants to a group conversation.
     * 
     * @Route("/conversations/{id}/participants", name="add_participant", methods={"POST"})
     * 
     * @OA\Post(
     *     path="/api/chat/conversations/{id}/participants",
     *     summary="Add one or multiple members to a group conversation (group admins only)",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la conversación grupal",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="userId",
     *                 description="ID del usuario a agregar (puede ser un ID individual o un array de IDs)",
     *                 oneOf={
     *                     @OA\Schema(type="integer", example=3),
     *                     @OA\Schema(type="array", @OA\Items(type="integer"), example={2, 3})
     *                 }
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Miembros agregados exitosamente"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Petición incorrecta o falta de IDs"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="No autorizado (debes ser administrador de la conversación)"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Conversación no encontrada"
     *     )
     * )
     */
    public function addParticipant(int $id, Request $request, ConversationRepository $convRepo, UserRepository $userRepository, EntityManagerInterface $em, AuditLogger $auditLogger): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Find the target conversation, ensuring it exists.
        $conversation = $convRepo->find($id);

        if (!$conversation) {
            return $this->json(['error' => 'Conversación no encontrada'], 404);
        }

        // Reject requests to add members to a private conversation.
        if ($conversation->getType() !== 'group') {
            return $this->json(['error' => 'No se pueden agregar participantes a una conversación privada'], 400);
        }

        // Verify current user is group admin
        $isGroupAdmin = false;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getUser()->getId() === $currentUser->getId()) {
                if ($p->getRole() === 'admin') {
                    $isGroupAdmin = true;
                }
                break;
            }
        }

        if (!$isGroupAdmin) {
            return $this->json(['error' => 'Solo los administradores de la conversación pueden agregar miembros'], 403);
        }

        // Parse the payload, supporting both single 'userId' and array of 'userIds'.
        $data = json_decode($request->getContent(), true);

        $userIdInput = $data['userId'] ?? [];
        if (!is_array($userIdInput)) {
            $userIdInput = [$userIdInput];
        }

        $userIds = array_unique(array_filter($userIdInput));

        if (empty($userIds)) {
            return $this->json(['error' => 'El ID de usuario es obligatorio'], 400);
        }

        $addedUsers = [];
        $failedUsers = [];

        // Process each user ID, adding them if new or reactivating if they previously left.
        foreach ($userIds as $userId) {
            $targetUser = $userRepository->find($userId);
            if (!$targetUser) {
                $failedUsers[] = ['userId' => $userId, 'error' => 'Usuario no encontrado'];
                continue;
            }

            // Check if already participant
            $alreadyParticipant = false;
            foreach ($conversation->getParticipants() as $p) {
                if ($p->getUser()->getId() === $targetUser->getId()) {
                    $alreadyParticipant = true;
                    // If they previously left or deleted, reactivate them
                    if ($p->getDeletedAt() !== null) {
                        $p->setDeletedAt(null);
                        $p->setJoinedAt(new \DateTime());
                        $p->setRole('member');
                        $addedUsers[] = ['userId' => $userId, 'status' => 'reactivated', 'name' => $targetUser->getDisplayName()];
                    } else {
                        $failedUsers[] = ['userId' => $userId, 'error' => 'El usuario ya es un participante activo'];
                    }
                    break;
                }
            }

            if (!$alreadyParticipant) {
                $participant = new ConversationParticipant();
                $participant->setUser($targetUser);
                $participant->setRole('member');
                $conversation->addParticipant($participant);
                $em->persist($participant);

                $addedUsers[] = ['userId' => $userId, 'status' => 'added', 'name' => $targetUser->getDisplayName()];
            }
        }

        // Apply and save all participant changes to the database.
        $em->flush();

        // Audit log the addition of participants
        if (!empty($addedUsers)) {
            $addedUserEmails = array_map(function ($item) use ($userRepository) {
                $u = $userRepository->find($item['userId']);
                return $u ? $u->getEmail() : 'unknown';
            }, $addedUsers);

            $auditLogger->log('ADD_PARTICIPANT', Conversation::class, (string) $conversation->getId(), [
                'conversation_name' => $conversation->getName(),
                'added_participants' => $addedUserEmails
            ]);
        }

        return $this->json([
            'status' => 'success',
            'added' => $addedUsers,
            'failed' => $failedUsers
        ]);
    }

    /**
     * Kick a participant from a group conversation.
     * 
     * @Route("/conversations/{id}/participants/{userId}", name="kick_participant", methods={"DELETE"})
     * 
     * @OA\Delete(
     *     path="/api/chat/conversations/{id}/participants/{userId}",
     *     summary="Kick a member from a group conversation",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la conversación grupal",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         description="ID del usuario a expulsar",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Miembro expulsado exitosamente"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="No puedes expulsarte a ti mismo"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="No autorizado (debes ser participante y tener rol administrador del sistema)"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Conversación o participante no encontrado"
     *     )
     * )
     */
    public function kickParticipant(int $id, int $userId, ConversationRepository $convRepo, EntityManagerInterface $em, AuditLogger $auditLogger): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Retrieve the group conversation by ID, validating its existence.
        $conversation = $convRepo->find($id);

        if (!$conversation) {
            return $this->json(['error' => 'Conversación no encontrada'], 404);
        }

        // Restrict kick operation strictly to group conversations.
        if ($conversation->getType() !== 'group') {
            return $this->json(['error' => 'No se pueden expulsar participantes de una conversación privada'], 400);
        }

        // Block users from kicking themselves.
        if ($currentUser->getId() === $userId) {
            return $this->json(['error' => 'No puedes expulsarte a ti mismo. Por favor usa el endpoint de salir para abandonar la conversación'], 400);
        }

        // Verify current user is group admin
        $isGroupAdmin = false;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getUser()->getId() === $currentUser->getId()) {
                if ($p->getRole() === 'admin') {
                    $isGroupAdmin = true;
                }
                break;
            }
        }

        if (!$isGroupAdmin) {
            return $this->json(['error' => 'Solo los administradores de la conversación pueden expulsar miembros'], 403);
        }

        // Locate the target participant record to be removed.
        $targetParticipant = null;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getUser()->getId() === $userId) {
                $targetParticipant = $p;
                break;
            }
        }

        if (!$targetParticipant) {
            return $this->json(['error' => 'Participante no encontrado en esta conversación'], 404);
        }

        $targetUser = $targetParticipant->getUser();
        $targetUserEmail = $targetUser ? $targetUser->getEmail() : 'unknown';

        // Remove the participant relationship from the database.
        $em->remove($targetParticipant);
        $em->flush();

        // Audit log the kick action
        $auditLogger->log('KICK_PARTICIPANT', Conversation::class, (string) $conversation->getId(), [
            'conversation_name' => $conversation->getName(),
            'kicked_user' => $targetUserEmail
        ]);

        return $this->json(['status' => 'success', 'message' => 'Usuario expulsado del grupo exitosamente']);
    }

    /**
     * Leave a group conversation.
     * 
     * @Route("/conversations/{id}/leave", name="leave_conversation", methods={"POST"})
     * 
     * @OA\Post(
     *     path="/api/chat/conversations/{id}/leave",
     *     summary="Voluntarily leave a group conversation",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la conversación a abandonar",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Has abandonado la conversación exitosamente"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="No se puede abandonar una conversación privada"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="No autorizado (no eres participante de esta conversación)"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Conversación no encontrada"
     *     )
     * )
     */
    public function leaveConversation(int $id, ConversationRepository $convRepo, EntityManagerInterface $em, AuditLogger $auditLogger): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Find the group conversation by ID, or return 404.
        $conversation = $convRepo->find($id);

        if (!$conversation) {
            return $this->json(['error' => 'Conversación no encontrada'], 404);
        }

        // Disallow leaving private conversations, as those can only be hidden.
        if ($conversation->getType() !== 'group') {
            return $this->json(['error' => 'No se puede abandonar una conversación privada. Usa el endpoint de eliminar para ocultarla'], 400);
        }

        // Locate the current user's participant entry.
        $targetParticipant = null;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getUser()->getId() === $currentUser->getId()) {
                $targetParticipant = $p;
                break;
            }
        }

        if (!$targetParticipant) {
            return $this->json(['error' => 'No eres un participante en esta conversación'], 403);
        }

        // Delete the current user's membership and save changes.
        $em->remove($targetParticipant);
        $em->flush();

        // Audit log the leave action
        $auditLogger->log('LEAVE_CONVERSATION', Conversation::class, (string) $conversation->getId(), [
            'conversation_name' => $conversation->getName(),
            'email' => $currentUser->getEmail()
        ]);

        return $this->json(['status' => 'success', 'message' => 'Has abandonado la conversación exitosamente']);
    }

    /**
     * Promote or demote a participant's role in a group conversation.
     * 
     * @Route("/conversations/{id}/participants/{userId}/role", name="update_participant_role", methods={"PUT"})
     * 
     * @OA\Put(
     *     path="/api/chat/conversations/{id}/participants/{userId}/role",
     *     summary="Promote or demote a member's role (admin / member)",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la conversación grupal",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="userId",
     *         in="path",
     *         description="ID del usuario cuyo rol será modificado",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="role", type="string", example="admin", description="Nuevo rol: admin o member")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Rol actualizado exitosamente"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Petición incorrecta, rol no válido o no es un chat grupal"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="No autorizado (no eres el administrador de la conversación)"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Conversación o participante no encontrado"
     *     )
     * )
     */
    public function updateParticipantRole(int $id, int $userId, Request $request, ConversationRepository $convRepo, EntityManagerInterface $em, AuditLogger $auditLogger): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Find the group conversation by ID, or return 404 if missing.
        $conversation = $convRepo->find($id);

        if (!$conversation) {
            return $this->json(['error' => 'Conversación no encontrada'], 404);
        }

        // Restrict role modification only to group conversations.
        if ($conversation->getType() !== 'group') {
            return $this->json(['error' => 'Los roles solo pueden ser actualizados en conversaciones grupales'], 400);
        }

        // Verify current user is a group admin
        $isGroupAdmin = false;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getUser()->getId() === $currentUser->getId()) {
                if ($p->getRole() === 'admin') {
                    $isGroupAdmin = true;
                }
                break;
            }
        }

        if (!$isGroupAdmin) {
            return $this->json(['error' => 'Solo los administradores de la conversación pueden promover o degradar miembros'], 403);
        }

        // Parse the target role and validate it against the allowed list.
        $data = json_decode($request->getContent(), true);
        $newRole = $data['role'] ?? null;

        if (!in_array($newRole, ['admin', 'member'])) {
            return $this->json(['error' => 'El rol debe ser admin o member'], 400);
        }

        // Find the participant to promote/demote among active group members.
        $targetParticipant = null;
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getUser()->getId() === $userId && $p->getDeletedAt() === null) {
                $targetParticipant = $p;
                break;
            }
        }

        if (!$targetParticipant) {
            return $this->json(['error' => 'Participante activo no encontrado en esta conversación'], 404);
        }

        // Prevent demoting self if they are the only admin
        if ($targetParticipant->getUser()->getId() === $currentUser->getId() && $newRole === 'member') {
            $adminCount = 0;
            foreach ($conversation->getParticipants() as $p) {
                if ($p->getDeletedAt() === null && $p->getRole() === 'admin') {
                    $adminCount++;
                }
            }
            if ($adminCount <= 1) {
                return $this->json(['error' => 'No puedes degradarte a ti mismo porque eres el único administrador restante en este grupo'], 400);
            }
        }

        // Apply the updated role and flush the changes.
        $targetParticipant->setRole($newRole);
        $em->flush();

        // Audit log the role update
        $targetUser = $targetParticipant->getUser();
        $targetUserEmail = $targetUser ? $targetUser->getEmail() : 'unknown';

        $auditLogger->log('UPDATE_PARTICIPANT_ROLE', Conversation::class, (string) $conversation->getId(), [
            'conversation_name' => $conversation->getName(),
            'target_user' => $targetUserEmail,
            'new_role' => $newRole
        ]);

        return $this->json([
            'status' => 'success',
            'message' => sprintf('Rol de usuario actualizado a %s exitosamente', $newRole)
        ]);
    }

    /**
     * Creates a Google Meet video call space for this conversation and broadcasts it to participants.
     * 
     * @Route("/conversations/{id}/meet", name="start_meet_call", methods={"POST"})
     * 
     * @OA\Post(
     *     path="/api/chat/conversations/{id}/meet",
     *     summary="Create a Google Meet call space inside a conversation context",
     *     tags={"Mensajeria"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID de la conversación",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Llamada de Meet creada exitosamente y publicada mediante Mercure"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="El usuario no participa en esta conversación"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Conversación no encontrada"
     *     )
     * )
     */
    public function startMeetCall(int $id, ConversationRepository $convRepo, \App\Service\GoogleMeetService $meetService, EntityManagerInterface $em, \Symfony\Component\Mercure\HubInterface $hub, AuditLogger $auditLogger): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $conversation = $convRepo->find($id);
        if (!$conversation) {
            return $this->json(['error' => 'Conversación no encontrada'], 404);
        }

        // Verify participation
        $isParticipant = false;
        $attendeeEmails = [];
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getDeletedAt() === null) {
                $attendeeEmails[] = $p->getUser()->getEmail();
                if ($p->getUser()->getId() === $currentUser->getId()) {
                    $isParticipant = true;
                }
            }
        }

        if (!$isParticipant) {
            return $this->json(['error' => 'No eres un participante en esta conversación'], 403);
        }

        // Create the Google Meet space
        $title = sprintf("Videollamada - %s", $conversation->getName() ?: "Chat " . $conversation->getId());
        $meetUrl = $meetService->createSpace($title, $attendeeEmails);

        // Audit log the meeting arrangement
        $auditLogger->log('MEET_ARRANGED', User::class, (string) $currentUser->getId(), [
            'meetUrl' => $meetUrl,
            'title' => $title,
            'attendees' => $attendeeEmails,
            'conversationId' => $conversation->getId(),
            'type' => 'chat'
        ]);

        // Record a system message in the chat database
        $msgContent = sprintf("Videollamada de Google Meet iniciada. Únete aquí: %s", $meetUrl);

        $chatMessage = new ChatMessage();
        $chatMessage->setContent($msgContent);
        $chatMessage->setSender($currentUser);
        $chatMessage->setConversation($conversation);
        $conversation->setUpdatedAt(new \DateTime());

        // Restore/unhide conversation for any participant who deleted/hid it previously
        foreach ($conversation->getParticipants() as $p) {
            if ($p->getDeletedAt() !== null) {
                $p->setDeletedAt(null);
            }
        }

        $em->persist($chatMessage);
        $em->flush();

        // Broadcast to Mercure hub
        $payload = [
            'type' => 'meet_call_started',
            'id' => $chatMessage->getId(),
            'conversationId' => $conversation->getId(),
            'senderId' => $currentUser->getId(),
            'senderName' => $currentUser->getDisplayName(),
            'message' => $msgContent,
            'meetUrl' => $meetUrl,
            'timestamp' => $chatMessage->getCreatedAt()->format('c')
        ];

        $update = new \Symfony\Component\Mercure\Update(
            "conversations/{$conversation->getId()}",
            json_encode($payload),
            true
        );

        try {
            $hub->publish($update);
        } catch (\Exception $e) {
            error_log('Mercure meeting publish failed: ' . $e->getMessage());
        }

        return $this->json($payload, 201);
    }
}
