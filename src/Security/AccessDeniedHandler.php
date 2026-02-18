<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

class AccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        // Add flash message
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add(
                'error',
                'You do not have permission to access this resource.'
            );
        }

        // Redirect to dashboard (or login if not authenticated)
        $route = $request->getSession()->has('_security_main')
            ? 'app_my_agent'
            : 'app_login';

        return new RedirectResponse($this->urlGenerator->generate($route));
    }
}
