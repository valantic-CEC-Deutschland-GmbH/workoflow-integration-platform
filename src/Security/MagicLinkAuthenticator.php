<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Organisation;
use App\Entity\UserOrganisation;
use App\Repository\UserRepository;
use App\Service\MagicLinkService;
use App\Service\UserRegistrationService;
use App\Service\AuditLogService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Psr\Log\LoggerInterface;

class MagicLinkAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UserRepository $userRepository,
        private UserRegistrationService $userRegistrationService,
        private MagicLinkService $magicLinkService,
        private AuditLogService $auditLogService,
        private RouterInterface $router,
        private LoggerInterface $logger
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'app_magic_link'
            && $request->query->has('token');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->query->get('token');

        // Validate token
        $payload = $this->magicLinkService->validateToken($token);

        if (!$payload) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired magic link');
        }

        $name = $payload['name'];
        $orgUuid = $payload['org_uuid'];
        $workflowUserId = $payload['workflow_user_id'];

        // Use email from token if provided, otherwise generate from name
        $email = $payload['email'] ?? $this->userRegistrationService->generateEmailFromName($name);

        // Create or find organisation
        $organisation = $this->userRegistrationService->createOrFindOrganisation($orgUuid);

        return new SelfValidatingPassport(
            new UserBadge($email, function ($userIdentifier) use ($name, $organisation, $workflowUserId, $request) {
                // Use the registration service to create or update the user
                // This handles both new users and existing users seamlessly
                $user = $this->userRegistrationService->createOrUpdateUser(
                    $name,
                    $userIdentifier,
                    $organisation,
                    $workflowUserId
                );

                // Store current organisation in session for context
                if ($request->hasSession()) {
                    $request->getSession()->set('current_organisation_id', $organisation->getId());
                }

                // Log successful authentication
                $this->auditLogService->log(
                    'user.authenticated_via_magic_link',
                    $user,
                    [
                        'organisation_id' => $organisation->getId(),
                        'organisation_name' => $organisation->getName()
                    ]
                );

                $this->logger->info('User authenticated via magic link', [
                    'email' => $userIdentifier,
                    'name' => $user->getName(),
                    'organisation' => $organisation->getName(),
                    'workflow_user_id' => $workflowUserId
                ]);

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Redirect to dashboard or originally requested page
        $targetPath = $request->getSession()->get('_security.' . $firewallName . '.target_path');

        if ($targetPath) {
            $request->getSession()->remove('_security.' . $firewallName . '.target_path');
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->router->generate('app_my_agent'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add(
                'error',
                $exception->getMessage() ?: 'Magic link authentication failed'
            );
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }

    private function extractNameFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        $localPart = $parts[0];

        // Replace common separators with spaces and capitalize
        $name = str_replace(['.', '_', '-'], ' ', $localPart);
        return ucwords($name);
    }
}
