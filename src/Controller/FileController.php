<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AuditLogService;
use App\Service\FileStorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/files')]
#[IsGranted('ROLE_ADMIN')]
class FileController extends AbstractController
{
    public function __construct(
        private FileStorageService $fileStorageService,
        private AuditLogService $auditLogService
    ) {
    }

    #[Route('/', name: 'app_files')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $files = [];
        $connectionError = null;
        $isConnected = false;

        try {
            // Check connection first
            $isConnected = $this->fileStorageService->isConnected();

            if ($isConnected) {
                $files = $this->fileStorageService->listFiles($organisation->getUuid());
            } else {
                $connectionError = 'Unable to connect to file storage service. Please check if MinIO is running and accessible.';
            }
        } catch (\Exception $e) {
            $connectionError = 'Error accessing file storage: ' . $e->getMessage();
            $this->addFlash('error', $connectionError);

            // Log the error for debugging
            error_log('FileController: Connection error - ' . $e->getMessage());
            error_log('FileController: isConnected = ' . ($isConnected ? 'true' : 'false'));
        }

        return $this->render('file/index.html.twig', [
            'files' => $files,
            'organisation' => $organisation,
            'connectionError' => $connectionError,
            'isConnected' => $isConnected,
        ]);
    }

    #[Route('/upload', name: 'app_file_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], 400);
        }

        // Handle files sent as 'files[]' from the frontend
        $uploadedFiles = $request->files->get('files', []);

        // If no files under 'files' key, check all files
        if (empty($uploadedFiles)) {
            $uploadedFiles = $request->files->all();
        }

        // Ensure we have files to process
        if (empty($uploadedFiles)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No files received in the request'
            ], 400);
        }

        $results = [];
        $errors = [];

        // Handle both array of files and single file
        if (!is_array($uploadedFiles)) {
            $uploadedFiles = [$uploadedFiles];
        }

        foreach ($uploadedFiles as $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {
                try {
                    $result = $this->fileStorageService->upload(
                        $uploadedFile,
                        $organisation->getUuid(),
                        $user->getId()
                    );

                    $results[] = $result;

                    $this->auditLogService->log(
                        'file.uploaded',
                        $user,
                        [
                            'filename' => $result['original_name'],
                            'size' => $result['size']
                        ]
                    );
                } catch (\Exception $e) {
                    $errors[] = [
                        'file' => $uploadedFile->getClientOriginalName(),
                        'error' => $e->getMessage()
                    ];
                }
            }
        }

        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'errors' => $errors,
                'uploaded' => $results
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
            'files' => $results
        ]);
    }

    #[Route('/{key}/delete', name: 'app_file_delete', methods: ['POST'], requirements: ['key' => '.+'])]
    public function delete(Request $request, string $key): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        // Verify file belongs to user's organisation
        if (!str_starts_with($key, $organisation->getUuid() . '/')) {
            throw $this->createAccessDeniedException();
        }

        if ($this->fileStorageService->delete($key)) {
            $this->auditLogService->log(
                'file.deleted',
                $user,
                ['key' => $key]
            );

            $this->addFlash('success', 'file.deleted.success');
        } else {
            $this->addFlash('error', 'file.deleted.error');
        }

        return $this->redirectToRoute('app_files');
    }

    #[Route('/{key}/download', name: 'app_file_download', requirements: ['key' => '.+'])]
    public function download(Request $request, string $key): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        // Verify file belongs to user's organisation
        if (!str_starts_with($key, $organisation->getUuid() . '/')) {
            throw $this->createAccessDeniedException();
        }

        try {
            $url = $this->fileStorageService->getDownloadUrl($key);

            $this->auditLogService->log(
                'file.downloaded',
                $user,
                ['key' => $key]
            );

            return $this->redirect($url);
        } catch (\Exception $e) {
            $this->addFlash('error', 'file.download.error');
            return $this->redirectToRoute('app_files');
        }
    }
}
