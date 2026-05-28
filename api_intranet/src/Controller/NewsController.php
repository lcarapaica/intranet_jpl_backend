<?php

namespace App\Controller;

use App\Entity\News;
use App\Entity\User;
use App\Repository\NewsRepository;
use App\Dto\NewsInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use OpenApi\Annotations as OA;

/**
 * Controller for managing Organization News.
 * 
 * @Route("/api/news", name="api_news_")
 */
class NewsController extends AbstractController
{
    /**
     * Lists active news articles with optional filters, searchable tags, and pagination.
     * 
     * @Route("", name="list", methods={"GET"})
     * @OA\Get(
     *     path="/api/news",
     *     summary="Lists organization news articles",
     *     tags={"Noticias"},
     *     @OA\Parameter(name="search", in="query", description="Search in title, body, author, or tags", @OA\Schema(type="string")),
     *     @OA\Parameter(name="category", in="query", description="Filter by exact category tag", @OA\Schema(type="string")),
     *     @OA\Parameter(name="limit", in="query", description="Page limit", @OA\Schema(type="integer", default=25)),
     *     @OA\Parameter(name="page", in="query", description="Page number", @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="sort", in="query", description="Sort field (postedAt, updatedAt, or id)", @OA\Schema(type="string", default="postedAt")),
     *     @OA\Parameter(name="order", in="query", description="Sort order (ASC, DESC)", @OA\Schema(type="string", default="DESC")),
     *     @OA\Response(response=200, description="List of news articles with pagination metadata")
     * )
     */
    public function list(Request $request, NewsRepository $repository): JsonResponse
    {
        // Extract filter, sorting, and pagination parameters from the query string
        $search = $request->query->get('search', '');
        $category = $request->query->get('category', null);
        $limit = $request->query->getInt('limit', 25);
        $page = $request->query->getInt('page', 1);
        $sort = $request->query->get('sort', 'postedAt');
        $order = $request->query->get('order', 'DESC');

        // Retrieve the filtered list. If the user has ROLE_NEWS_EDITOR permissions they can also view soft-deleted news articles.
        $result = $repository->searchAndPaginate([
            'search'          => $search,
            'category'        => $category,
            'limit'           => $limit,
            'page'            => $page,
            'sort'            => $sort,
            'order'           => $order,
            'active'          => true,
            'show_deleted_at' => $this->isGranted('ROLE_NEWS_EDITOR')
        ]);

        return $this->json($result);
    }

    /**
     * Retrieves details of a single news article.
     * 
     * @Route("/{id}", name="show", methods={"GET"}, requirements={"id"="\d+"})
     * @OA\Get(
     *     path="/api/news/{id}",
     *     summary="Get details of a single news article",
     *     tags={"Noticias"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="News article details"),
     *     @OA\Response(response=404, description="News article not found")
     * )
     */
    public function show(int $id, NewsRepository $repository): JsonResponse
    {
        // Check if the current user has news editor permissions
        $isEditor = $this->isGranted('ROLE_NEWS_EDITOR');

        // Editors can fetch any article, users only active ones
        if ($isEditor) {
            $news = $repository->find($id);
        } else {
            $news = $repository->findOneBy(['id' => $id, 'deletedAt' => null]);
        }

        // Return a 404 response if the article does not exist or has been soft-deleted for a non-editor
        if (!$news) {
            return $this->json(['error' => 'Noticia no encontrada'], 404);
        }

        // Map the News entity properties into a clean array structure 
        $response = [
            'id'        => $news->getId(),
            'title'     => $news->getTitle(),
            'body'      => $news->getBody(),
            'category'  => $news->getCategory(),
            'postedAt'  => $news->getPostedAt() ? $news->getPostedAt()->format('Y-m-d H:i:s') : null,
            'updatedAt' => $news->getUpdatedAt() ? $news->getUpdatedAt()->format('Y-m-d H:i:s') : null,
            'isActive'  => $news->isActive(),
            'author'    => [
                'id'   => $news->getAuthor()->getId(),
                'name' => $news->getAuthor()->getDisplayName()
            ]
        ];

        // Only include the logical deletion timestamp if the user is an editor
        if ($isEditor) {
            $response['deletedAt'] = $news->getDeletedAt() ? $news->getDeletedAt()->format('Y-m-d H:i:s') : null;
        }

        return $this->json($response);
    }

    /**
     * Creates a new news article.
     * 
     * @Route("", name="create", methods={"POST"})
     * @OA\Post(
     *     path="/api/news",
     *     summary="Create a new organization news article (Editors/Admins only)",
     *     tags={"Noticias"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="title", type="string", example="Feriado Administrativo del 30 de Mayo"),
     *             @OA\Property(property="body", type="string", example="Se informa a todo el personal que el día 30 de Mayo..."),
     *             @OA\Property(property="category", type="string", example="RRHH", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="News article successfully created"),
     *     @OA\Response(response=400, description="Invalid request or validation error"),
     *     @OA\Response(response=403, description="Insufficient permissions")
     * )
     */
    public function create(Request $request, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        // Enforce news editor permissions - only designated editors or admins can publish news
        if (!$this->isGranted('ROLE_NEWS_EDITOR')) {
            return $this->json(['error' => 'No tienes permisos para publicar noticias.'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Decode request payload body
        $data = json_decode($request->getContent(), true) ?: [];

        // Check required fields before initializing the entity
        $titleInput = isset($data['title']) && is_scalar($data['title']) ? (string)$data['title'] : '';
        if (trim($titleInput) === '') {
            return $this->json(['error' => 'El título es obligatorio'], 400);
        }

        $news = new News();
        $news->setAuthor($user);

        // Map request parameters into the news instance using the input DTO
        $dto = NewsInput::fromArray($data);
        $dto->updateEntity($news, array_keys($data));

        // Validate the entity rules 
        $errors = $validator->validate($news);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        // Save the new news article into the database
        $em->persist($news);
        $em->flush();

        return $this->json([
            'message' => 'Noticia publicada exitosamente',
            'id'      => $news->getId()
        ], 201);
    }

    /**
     * Updates an existing news article.
     * 
     * @Route("/{id}", name="update", methods={"PUT"}, requirements={"id"="\d+"})
     * @OA\Put(
     *     path="/api/news/{id}",
     *     summary="Update a news article (Author or Admins only)",
     *     tags={"Noticias"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="title", type="string", example="Feriado Administrativo Actualizado"),
     *             @OA\Property(property="body", type="string", example="Se informa a todo el personal el nuevo horario..."),
     *             @OA\Property(property="category", type="string", example="General", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="News article updated successfully"),
     *     @OA\Response(response=403, description="Unauthorized to edit this article"),
     *     @OA\Response(response=404, description="News article not found")
     * )
     */
    public function update(int $id, Request $request, NewsRepository $repository, EntityManagerInterface $em, ValidatorInterface $validator): JsonResponse
    {
        // Enforce generic role access for news editors
        if (!$this->isGranted('ROLE_NEWS_EDITOR')) {
            return $this->json(['error' => 'No tienes permisos para editar noticias.'], 403);
        }

        // Fetch only active, non-deleted news article by ID
        $news = $repository->findOneBy(['id' => $id, 'deletedAt' => null]);

        if (!$news) {
            return $this->json(['error' => 'Noticia no encontrada'], 404);
        }

        // Only the author of the post or an admincan edit the article
        $isAuthor = ($news->getAuthor() === $this->getUser());
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if (!$isAuthor && !$isAdmin) {
            return $this->json(['error' => 'No tienes permisos para editar esta noticia.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?: [];

        // Apply provided field changes onto the existing Entity through the input DTO mapping
        $dto = NewsInput::fromArray($data);
        $dto->updateEntity($news, array_keys($data));

        $errors = $validator->validate($news);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(' ', $errorMessages)], 400);
        }

        $em->flush();

        return $this->json(['message' => 'Noticia actualizada correctamente']);
    }

    /**
     * Soft-deletes a news article.
     * 
     * @Route("/{id}", name="delete", methods={"DELETE"}, requirements={"id"="\d+"})
     * @OA\Delete(
     *     path="/api/news/{id}",
     *     summary="Soft delete a news article (Author or Admins only)",
     *     tags={"Noticias"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="News article logically deleted successfully"),
     *     @OA\Response(response=403, description="Unauthorized to delete this article"),
     *     @OA\Response(response=404, description="News article not found")
     * )
     */
    public function delete(int $id, NewsRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        // Enforce generic role access for news editors
        if (!$this->isGranted('ROLE_NEWS_EDITOR')) {
            return $this->json(['error' => 'No tienes permisos para eliminar noticias.'], 403);
        }

        // Fetch only active, non-deleted news article
        $news = $repository->findOneBy(['id' => $id, 'deletedAt' => null]);

        if (!$news) {
            return $this->json(['error' => 'Noticia no encontrada'], 404);
        }

        // Only the author of the post or an admin can delete it
        $isAuthor = ($news->getAuthor() === $this->getUser());
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if (!$isAuthor && !$isAdmin) {
            return $this->json(['error' => 'No tienes permisos para eliminar esta noticia.'], 403);
        }

        // Apply logical soft-delete
        $news->setDeletedAt(new \DateTime());
        $em->flush();

        return $this->json(['message' => 'Noticia eliminada lógicamente de forma exitosa']);
    }

    /**
     * Toggles the active status (soft-delete or restore) of a news article.
     * 
     * @Route("/{id}/toggle", name="toggle", methods={"PATCH"}, requirements={"id"="\d+"})
     * @OA\Patch(
     *     path="/api/news/{id}/toggle",
     *     summary="Toggle active status of a news article (Author or Admins only)",
     *     tags={"Noticias"},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Active status toggled successfully"),
     *     @OA\Response(response=403, description="Unauthorized to toggle status for this article"),
     *     @OA\Response(response=404, description="News article not found")
     * )
     */
    public function toggle(int $id, NewsRepository $repository, EntityManagerInterface $em): JsonResponse
    {
        // Enforce generic role access for news editors
        if (!$this->isGranted('ROLE_NEWS_EDITOR')) {
            return $this->json(['error' => 'No tienes permisos para modificar noticias.'], 403);
        }

        // Fetch news article by ID, regardless of its current soft-deleted state
        $news = $repository->find($id);

        if (!$news) {
            return $this->json(['error' => 'Noticia no encontrada'], 404);
        }

        //  Only the author of the post or an admin can toggle it
        $isAuthor = ($news->getAuthor() === $this->getUser());
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        if (!$isAuthor && !$isAdmin) {
            return $this->json(['error' => 'No tienes permisos para modificar esta noticia.'], 403);
        }

        if ($news->getDeletedAt() === null) {
            // Deactivate / logical delete
            $news->setDeletedAt(new \DateTime());
            $message = 'Noticia desactivada (eliminada lógicamente) correctamente';
        } else {
            // Restore
            $news->setDeletedAt(null);
            $message = 'Noticia restaurada (activada) correctamente';
        }

        $em->flush();

        return $this->json([
            'message'  => $message,
            'isActive' => $news->isActive()
        ]);
    }
}
