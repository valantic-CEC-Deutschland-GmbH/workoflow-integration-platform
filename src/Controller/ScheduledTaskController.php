<?php

namespace App\Controller;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskExecution;
use App\Entity\User;
use App\Enum\TaskFrequency;
use App\Message\ExecuteScheduledTaskMessage;
use App\Repository\ScheduledTaskExecutionRepository;
use App\Repository\ScheduledTaskRepository;
use App\Service\AuditLogService;
use App\Service\ScheduledTask\ResponseRendererRegistry;
use App\Service\ScheduledTask\ScheduledTaskExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/scheduled-tasks')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ScheduledTaskController extends AbstractController
{
    public function __construct(
        private ScheduledTaskRepository $taskRepository,
        private ScheduledTaskExecutionRepository $executionRepository,
        private ScheduledTaskExecutor $executor,
        private AuditLogService $auditLogService,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private MessageBusInterface $messageBus,
        private ResponseRendererRegistry $responseRendererRegistry,
    ) {
    }

    #[Route('/', name: 'app_scheduled_tasks')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $tasks = $this->taskRepository->findByOrganisationAndUser($organisation, $user);
        $executions = $this->executionRepository->findByOrganisation($organisation, 50);

        return $this->render('scheduled_task/index.html.twig', [
            'tasks' => $tasks,
            'executions' => $executions,
            'organisation' => $organisation,
            'frequencies' => TaskFrequency::choices(),
        ]);
    }

    #[Route('/new', name: 'app_scheduled_task_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        if ($request->isMethod('POST')) {
            $task = new ScheduledTask();
            $task->setName((string) $request->request->get('name'));
            $task->setDescription($request->request->get('description'));
            $task->setPrompt((string) $request->request->get('prompt'));
            $task->setFrequency((string) $request->request->get('frequency', 'manual'));
            $task->setExecutionTime($request->request->get('execution_time') ?: null);
            $task->setActive($request->request->getBoolean('active', true));

            $weekday = $request->request->get('weekday');
            $task->setWeekday($weekday !== null && $weekday !== '' ? (int) $weekday : null);

            $task->setUser($user);
            $task->setOrganisation($organisation);
            $task->computeNextExecutionAt();

            $this->entityManager->persist($task);
            $this->entityManager->flush();

            $this->auditLogService->logWithOrganisation('scheduled_task.created', $organisation, $user, [
                'task_uuid' => $task->getUuid(),
                'task_name' => $task->getName(),
                'frequency' => $task->getFrequency(),
            ]);

            $this->addFlash('success', $this->translator->trans('scheduled_task.created.success'));

            return $this->redirectToRoute('app_scheduled_tasks');
        }

        return $this->render('scheduled_task/form.html.twig', [
            'task' => null,
            'organisation' => $organisation,
            'frequencies' => TaskFrequency::choices(),
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_scheduled_task_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $task = $this->taskRepository->findByUuidAndOrganisation($uuid, $organisation);

        if (!$task || $task->getUser() !== $user) {
            throw $this->createNotFoundException('Scheduled task not found');
        }

        if ($request->isMethod('POST')) {
            $task->setName((string) $request->request->get('name'));
            $task->setDescription($request->request->get('description'));
            $task->setPrompt((string) $request->request->get('prompt'));
            $task->setFrequency((string) $request->request->get('frequency', 'manual'));
            $task->setExecutionTime($request->request->get('execution_time') ?: null);
            $task->setActive($request->request->getBoolean('active', true));

            $weekday = $request->request->get('weekday');
            $task->setWeekday($weekday !== null && $weekday !== '' ? (int) $weekday : null);

            $task->computeNextExecutionAt();

            $this->entityManager->flush();

            $this->auditLogService->logWithOrganisation('scheduled_task.updated', $organisation, $user, [
                'task_uuid' => $task->getUuid(),
                'task_name' => $task->getName(),
                'frequency' => $task->getFrequency(),
            ]);

            $this->addFlash('success', $this->translator->trans('scheduled_task.updated.success'));

            return $this->redirectToRoute('app_scheduled_tasks');
        }

        return $this->render('scheduled_task/form.html.twig', [
            'task' => $task,
            'organisation' => $organisation,
            'frequencies' => TaskFrequency::choices(),
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_scheduled_task_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $task = $this->taskRepository->findByUuidAndOrganisation($uuid, $organisation);

        if (!$task || $task->getUser() !== $user) {
            throw $this->createNotFoundException('Scheduled task not found');
        }

        if (!$this->isCsrfTokenValid('delete-task-' . $uuid, $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        $taskName = $task->getName();
        $this->entityManager->remove($task);
        $this->entityManager->flush();

        $this->auditLogService->logWithOrganisation('scheduled_task.deleted', $organisation, $user, [
            'task_uuid' => $uuid,
            'task_name' => $taskName,
        ]);

        $this->addFlash('success', $this->translator->trans('scheduled_task.deleted.success'));

        return $this->redirectToRoute('app_scheduled_tasks');
    }

    #[Route('/execution/{id}/delete', name: 'app_scheduled_task_execution_delete', methods: ['POST'])]
    public function deleteExecution(int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return $this->redirectToRoute('app_tenant_create');
        }

        $execution = $this->entityManager->getRepository(ScheduledTaskExecution::class)->find($id);

        if (!$execution || $execution->getScheduledTask()->getOrganisation() !== $organisation) {
            throw $this->createNotFoundException('Execution not found');
        }

        if (!$this->isCsrfTokenValid('delete-execution-' . $id, $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException();
        }

        $execution->setDeletedAt(new \DateTime());
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('scheduled_task.execution_deleted.success'));

        return $this->redirectToRoute('app_scheduled_tasks');
    }

    #[Route('/{uuid}/test', name: 'app_scheduled_task_test', methods: ['POST'])]
    public function test(string $uuid, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return new JsonResponse(['success' => false, 'message' => 'No organisation'], Response::HTTP_BAD_REQUEST);
        }

        $task = $this->taskRepository->findByUuidAndOrganisation($uuid, $organisation);

        if (!$task || $task->getUser() !== $user) {
            return new JsonResponse(['success' => false, 'message' => 'Task not found'], Response::HTTP_NOT_FOUND);
        }

        $execution = $this->executor->createPendingExecution($task, 'test');
        $this->entityManager->flush();

        $this->messageBus->dispatch(new ExecuteScheduledTaskMessage(
            $task->getId(),
            $execution->getId(),
            'test',
        ));

        return new JsonResponse([
            'success' => true,
            'status' => 'pending',
            'executionId' => $execution->getId(),
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/{uuid}/run', name: 'app_scheduled_task_run', methods: ['POST'])]
    public function runNow(string $uuid, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return new JsonResponse(['success' => false, 'message' => 'No organisation'], Response::HTTP_BAD_REQUEST);
        }

        $task = $this->taskRepository->findByUuidAndOrganisation($uuid, $organisation);

        if (!$task || $task->getUser() !== $user) {
            return new JsonResponse(['success' => false, 'message' => 'Task not found'], Response::HTTP_NOT_FOUND);
        }

        $execution = $this->executor->createPendingExecution($task, 'manual');
        $this->entityManager->flush();

        $this->messageBus->dispatch(new ExecuteScheduledTaskMessage(
            $task->getId(),
            $execution->getId(),
            'manual',
        ));

        return new JsonResponse([
            'success' => true,
            'status' => 'pending',
            'executionId' => $execution->getId(),
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/execution/{id}/status', name: 'app_scheduled_task_execution_status', methods: ['GET'])]
    public function executionStatus(int $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], Response::HTTP_BAD_REQUEST);
        }

        $execution = $this->entityManager->getRepository(ScheduledTaskExecution::class)->find($id);

        if (!$execution || $execution->getScheduledTask()->getOrganisation() !== $organisation) {
            return new JsonResponse(['error' => 'Execution not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'status' => $execution->getStatus(),
            'output' => $execution->getOutput(),
            'errorMessage' => $execution->getErrorMessage(),
            'httpStatusCode' => $execution->getHttpStatusCode(),
            'duration' => $execution->getDuration(),
        ]);
    }

    #[Route('/execution/{id}/output', name: 'app_scheduled_task_execution_output', methods: ['GET'])]
    public function executionOutput(int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return new JsonResponse(['error' => 'No organisation'], Response::HTTP_BAD_REQUEST);
        }

        $execution = $this->entityManager->getRepository(ScheduledTaskExecution::class)->find($id);

        if (!$execution || $execution->getScheduledTask()->getOrganisation() !== $organisation) {
            return new JsonResponse(['error' => 'Execution not found'], Response::HTTP_NOT_FOUND);
        }

        $rawOutput = $execution->getOutput() ?? $execution->getErrorMessage() ?? '';
        $tenantType = $organisation->getTenantType() ?? '';

        $renderedHtml = $this->responseRendererRegistry->render($tenantType, $rawOutput);

        return new JsonResponse([
            'html' => $renderedHtml,
            'status' => $execution->getStatus(),
        ]);
    }

    #[Route('/{uuid}/toggle', name: 'app_scheduled_task_toggle', methods: ['POST'])]
    public function toggle(string $uuid, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessionOrgId = $request->getSession()->get('current_organisation_id');
        $organisation = $user->getCurrentOrganisation($sessionOrgId);

        if (!$organisation) {
            return new JsonResponse(['success' => false, 'message' => 'No organisation'], Response::HTTP_BAD_REQUEST);
        }

        $task = $this->taskRepository->findByUuidAndOrganisation($uuid, $organisation);

        if (!$task || $task->getUser() !== $user) {
            return new JsonResponse(['success' => false, 'message' => 'Task not found'], Response::HTTP_NOT_FOUND);
        }

        $task->setActive(!$task->isActive());
        if ($task->isActive()) {
            $task->computeNextExecutionAt();
        }
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'active' => $task->isActive(),
        ]);
    }
}
