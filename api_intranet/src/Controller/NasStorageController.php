<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\NasStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use OpenApi\Annotations as OA;

/**
 * Controller to manage files in the Corporate NAS via WebDAV.
 *
 * @Route("/api/nas")
 */
class NasStorageController extends AbstractController
{
    private $nasService;

    public function __construct(NasStorageService $nasService)
    {
        $this->nasService = $nasService;
    }

    /**
     * @Route("/files", name="api_nas_files", methods={"GET"})
     * @OA\Get(
     *     path="/api/nas/files",
     *     summary="Lists files and directories on the NAS",
     *     tags={"NAS Storage"},
     *     @OA\Parameter(name="path", in="query", description="Subfolder path to list", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="List of files and directories"),
     *     @OA\Response(response=401, description="Not authenticated"),
     *     @OA\Response(response=400, description="WebDAV error")
     * )
     */
    public function listFiles(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Usuario no autenticado'], 401);
        }

        $path = $request->query->get('path', '');

        try {
            $files = $this->nasService->listFiles($path);
            return new JsonResponse($files, 200);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @Route("/download", name="api_nas_download", methods={"GET"})
     * @OA\Get(
     *     path="/api/nas/download",
     *     summary="Downloads or previews a file from the NAS",
     *     tags={"NAS Storage"},
     *     @OA\Parameter(name="file", in="query", required=true, description="Relative file path to download/preview", @OA\Schema(type="string")),
     *     @OA\Parameter(name="disposition", in="query", required=false, description="Content disposition style (attachment or inline)", @OA\Schema(type="string", default="attachment")),
     *     @OA\Response(response=200, description="File content stream"),
     *     @OA\Response(response=401, description="Not authenticated"),
     *     @OA\Response(response=404, description="File not found")
     * )
     */
    public function downloadFile(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new Response('Usuario no autenticado', 401);
        }

        $file = $request->query->get('file', '');
        if (empty($file)) {
            return new Response('Nombre de archivo faltante', 400);
        }

        $disposition = $request->query->get('disposition', 'attachment');
        if (!in_array($disposition, ['attachment', 'inline'], true)) {
            $disposition = 'attachment';
        }

        try {
            $fileData = $this->nasService->downloadFile($file);

            $response = new Response($fileData['content']);
            $response->headers->set('Content-Type', $fileData['contentType']);
            $response->headers->set(
                'Content-Disposition',
                sprintf('%s; filename="%s"', $disposition, $fileData['name'])
            );

            return $response;
        } catch (\Exception $e) {
            return new Response('Error al descargar archivo: ' . $e->getMessage(), 400);
        }
    }

    /**
     * @Route("/upload", name="api_nas_upload", methods={"POST"})
     * @OA\Post(
     *     path="/api/nas/upload",
     *     summary="Uploads a file to the NAS",
     *     tags={"NAS Storage"},
     *     @OA\Parameter(name="path", in="query", description="Target folder path on the NAS", @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="file", type="string", format="binary", description="File to upload")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="File uploaded successfully"),
     *     @OA\Response(response=401, description="Not authenticated"),
     *     @OA\Response(response=400, description="Upload error")
     * )
     */
    public function uploadFile(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Usuario no autenticado'], 401);
        }

        $path = $request->query->get('path', '');
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile) {
            return new JsonResponse(['error' => 'No se recibió ningún archivo.'], 400);
        }

        try {
            $originalName = $uploadedFile->getClientOriginalName();
            $targetPath = $path !== '' ? rtrim($path, '/') . '/' . $originalName : $originalName;

            $content = file_get_contents($uploadedFile->getPathname());

            $this->nasService->uploadFile($targetPath, $content);

            return new JsonResponse(['message' => 'Archivo subido correctamente.'], 201);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @Route("/delete", name="api_nas_delete", methods={"DELETE"})
     * @OA\Delete(
     *     path="/api/nas/delete",
     *     summary="Deletes a file or directory from the NAS",
     *     tags={"NAS Storage"},
     *     @OA\Parameter(name="file", in="query", required=true, description="File path to delete", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="File deleted successfully"),
     *     @OA\Response(response=401, description="Not authenticated"),
     *     @OA\Response(response=400, description="Deletion error")
     * )
     */
    public function deleteFile(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Usuario no autenticado'], 401);
        }

        $file = $request->query->get('file', '');
        if (empty($file)) {
            return new JsonResponse(['error' => 'Debe especificar el archivo a borrar.'], 400);
        }

        try {
            $this->nasService->deleteFile($file);
            return new JsonResponse(['message' => 'Archivo borrado correctamente.'], 200);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
