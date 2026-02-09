<?php

namespace App\Controller;

use App\Entity\WaitlistEntry;
use App\Repository\WaitlistEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // If user is already authenticated, redirect to dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_general');
        }

        return $this->render('home/index.html.twig');
    }

    #[Route('/locale/switch/{locale}', name: 'app_locale_switch')]
    public function switchLocale(string $locale, Request $request): Response
    {
        // Validate locale against supported locales
        $supportedLocales = ['de', 'en', 'ro', 'lt'];
        if (!in_array($locale, $supportedLocales)) {
            throw $this->createNotFoundException('Invalid locale');
        }

        // Store locale in session
        $session = $request->getSession();
        $session->set('_locale', $locale);

        // Redirect back to the referring page or home
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_home');
    }

    #[Route('/waitlist/submit', name: 'app_waitlist_submit', methods: ['POST'])]
    public function submitWaitlist(
        Request $request,
        WaitlistEntryRepository $repository,
        ValidatorInterface $validator
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;

        if (!$email) {
            return $this->json(['success' => false, 'error' => 'Email is required'], 400);
        }

        // Check if email already exists
        $existing = $repository->findByEmail($email);
        if ($existing) {
            return $this->json(['success' => false, 'error' => 'This email is already on the waitlist'], 400);
        }

        // Create new waitlist entry
        $entry = new WaitlistEntry();
        $entry->setEmail($email);
        $entry->setIpAddress($request->getClientIp());

        // Validate the entry
        $errors = $validator->validate($entry);
        if (count($errors) > 0) {
            return $this->json(['success' => false, 'error' => 'Invalid email address'], 400);
        }

        // Save to database
        try {
            $repository->save($entry, true);
            return $this->json(['success' => true, 'message' => 'Successfully joined the waitlist!']);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'An error occurred. Please try again.'], 500);
        }
    }
}
