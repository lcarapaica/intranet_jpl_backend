<?php

namespace App\Repository;

use App\Entity\ChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 *
 * @method ChatMessage|null find($id, $lockMode = null, $lockVersion = null)
 * @method ChatMessage|null findOneBy(array $criteria, array $orderBy = null)
 * @method ChatMessage[]    findAll()
 * @method ChatMessage[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * Finds active messages in a conversation with optional exact word search and pagination.
     */
    public function findMessagesForConversation(int $conversationId, int $limit = 50, ?int $beforeId = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversationId')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('conversationId', $conversationId);

        // Fetch messages older than the given beforeId for cursor pagination
        if ($beforeId !== null) {
            $qb->andWhere('m.id < :beforeId')
                ->setParameter('beforeId', $beforeId);
        }

        // Exact word filter: ensures keyword matches stand-alone words rather than substrings (e.g. searching 'a' matches word 'a', not 'apple')
        if ($search !== null && trim($search) !== '') {
            $conn = $this->getEntityManager()->getConnection();
            $escapedSearch = preg_quote(trim($search), '/');
            $escapedSearch = str_replace('\\', '\\\\', $escapedSearch);
            // Universal exact word boundary pattern matching alphanumeric and accented characters
            $pattern = '(^|[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ])' . $escapedSearch . '($|[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ])';

            $sql = 'SELECT id FROM chat_message WHERE conversation_id = :conversationId AND deleted_at IS NULL AND content REGEXP :pattern';
            // Prepares and executes the query
            $stmt = $conn->executeQuery($sql, [
                'conversationId' => $conversationId,
                'pattern' => $pattern
            ]);
            // Fetches the rows of the query
            $rows = method_exists($stmt, 'fetchAllAssociative') ? $stmt->fetchAllAssociative() : $stmt->fetchAll();
            $matchingIds = array_column($rows, 'id');

            if (empty($matchingIds)) {
                return [];
            }

            $qb->andWhere('m.id IN (:matchingIds)')
                ->setParameter('matchingIds', $matchingIds);
        }

        // Return latest messages
        return $qb->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
