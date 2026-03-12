<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $entityManager,
        private RouterInterface $router,
        private UserRepository $userRepository,
        private AuditLogService $auditLogService
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
                /** @var \League\OAuth2\Client\Provider\GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $existingUser = $this->userRepository->findOneBy(['email' => $googleUser->getEmail()]);

                if ($existingUser) {
                    $existingUser->setGoogleId($googleUser->getId());
                    $existingUser->setAccessToken($accessToken->getToken());

                    // Store refresh token if available
                    if ($accessToken->getRefreshToken()) {
                        $existingUser->setRefreshToken($accessToken->getRefreshToken());
                    }

                    // Set token expiry time (Google tokens typically expire after 1 hour)
                    if ($accessToken->getExpires()) {
                        $expiresAt = new \DateTime();
                        $expiresAt->setTimestamp($accessToken->getExpires());
                        $existingUser->setTokenExpiresAt($expiresAt);
                    }

                    $this->entityManager->flush();

                    $this->auditLogService->log(
                        'user.login',
                        $existingUser,
                        ['provider' => 'google']
                    );

                    return $existingUser;
                }

                $user = new User();
                $user->setEmail($googleUser->getEmail());
                $user->setName($googleUser->getName());
                $user->setGoogleId($googleUser->getId());
                $user->setAccessToken($accessToken->getToken());

                // Store refresh token if available
                if ($accessToken->getRefreshToken()) {
                    $user->setRefreshToken($accessToken->getRefreshToken());
                }

                // Set token expiry time
                if ($accessToken->getExpires()) {
                    $expiresAt = new \DateTime();
                    $expiresAt->setTimestamp($accessToken->getExpires());
                    $user->setTokenExpiresAt($expiresAt);
                }

                $user->setRoles([User::ROLE_USER, User::ROLE_MEMBER]);

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->auditLogService->log(
                    'user.register',
                    $user,
                    ['provider' => 'google']
                );

                return $user;
            }),
            [new RememberMeBadge()]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $targetUrl = $this->router->generate('app_my_agent');
        return new RedirectResponse($targetUrl);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());
        $request->getSession()->getFlashBag()->add('error', $message);

        return new RedirectResponse($this->router->generate('app_login'));
    }
}
