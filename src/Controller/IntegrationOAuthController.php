<?php

namespace App\Controller;

use App\Entity\IntegrationConfig;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/integrations/oauth')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class IntegrationOAuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EncryptionService $encryptionService,
        private HttpClientInterface $httpClient
    ) {
    }

    #[Route('/microsoft/start/{configId}', name: 'app_tool_oauth_microsoft_start')]
    public function microsoftStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('microsoft_oauth_config_id', $configId);

        // Override Azure AD tenant if user specified a SharePoint URL
        $tenant = null;
        $encryptedCreds = $config->getEncryptedCredentials();
        if ($encryptedCreds) {
            $saved = json_decode($this->encryptionService->decrypt($encryptedCreds), true);
            $sharepointUrl = $saved['sharepoint_url'] ?? null;
            if (!empty($sharepointUrl)) {
                $tenant = $this->resolveTenantFromSharePointUrl($sharepointUrl);
                if (!$tenant) {
                    $this->addFlash('error', 'Could not resolve Azure AD tenant from SharePoint URL "' . $sharepointUrl . '". Please check the URL and try again.');
                    return $this->redirectToRoute('app_skills');
                }
            }
        }

        $client = $clientRegistry->getClient('azure');
        if ($tenant) {
            /** @var \TheNetworg\OAuth2\Client\Provider\Azure $azureProvider */
            $azureProvider = $client->getOAuth2Provider();
            $azureProvider->tenant = $tenant;
        }

        // Redirect to Microsoft OAuth
        return $client->redirect([
            'openid',
            'profile',
            'email',
            'offline_access',
            'https://graph.microsoft.com/Sites.Read.All',
            'https://graph.microsoft.com/Files.Read.All',
            'https://graph.microsoft.com/User.Read'
        ], []);
    }

    #[Route('/callback/microsoft', name: 'app_tool_oauth_microsoft_callback')]
    public function microsoftCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('microsoft_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                // If this was initial setup and user cancelled, remove the temporary config
                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('microsoft_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'SharePoint setup cancelled. Microsoft authorization is required to use this integration.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('microsoft_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

        // Continue with normal flow
        $configId = $request->getSession()->get('microsoft_oauth_config_id');

        if (!$configId) {
            $this->addFlash('error', 'OAuth session expired. Please try again.');
            return $this->redirectToRoute('app_skills');
        }

        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        try {
            // Get the OAuth2 client
            $client = $clientRegistry->getClient('azure');

            // Get the access token
            $accessToken = $client->getAccessToken();

            // Preserve existing credentials (e.g. sharepoint_url for tenant selection)
            $existingCredentials = [];
            if ($config->getEncryptedCredentials()) {
                $existingCredentials = json_decode(
                    $this->encryptionService->decrypt($config->getEncryptedCredentials()),
                    true
                ) ?: [];
            }

            // Merge OAuth tokens with existing credentials
            $credentials = array_merge($existingCredentials, [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires(),
                'tenant_id' => $accessToken->getValues()['tenant_id'] ?? 'common',
                'client_id' => $_ENV['AZURE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['AZURE_CLIENT_SECRET'] ?? ''
            ]);

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            // Auto-disable less useful tools for SharePoint on first setup
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if (
                $config->getIntegrationType() === 'sharepoint' &&
                $oauthFlowIntegration &&
                $oauthFlowIntegration == $configId &&
                empty($config->getDisabledTools())
            ) {
                // These tools are less useful for AI agents, disable by default
                $toolsToDisable = [
                    'sharepoint_list_files',      // Agents should search, not browse
                    'sharepoint_download_file',    // Only returns URL, agent can't fetch it
                    'sharepoint_get_list_items'    // Too technical for most use cases
                ];

                foreach ($toolsToDisable as $toolName) {
                    $config->disableTool($toolName);
                }

                error_log('Auto-disabled less useful SharePoint tools for new OAuth integration: ' . implode(', ', $toolsToDisable));
            }

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('microsoft_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'SharePoint integration created and connected successfully!');
            } else {
                $this->addFlash('success', 'SharePoint integration connected successfully!');
            }

            return $this->redirectToRoute('app_skills');
        } catch (\Exception $e) {
            // If this was initial setup and failed, remove the temporary config
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->entityManager->remove($config);
                $this->entityManager->flush();
            }

            $this->addFlash('error', 'Failed to connect to SharePoint: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }

    // ========================================
    // HubSpot OAuth2 Flow
    // ========================================

    #[Route('/hubspot/start/{configId}', name: 'app_tool_oauth_hubspot_start')]
    public function hubspotStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('hubspot_oauth_config_id', $configId);

        // Redirect to HubSpot OAuth with CRM scopes
        return $clientRegistry
            ->getClient('hubspot')
            ->redirect([
                'crm.objects.contacts.read',
                'crm.objects.contacts.write',
                'crm.objects.companies.read',
                'crm.objects.companies.write',
                'crm.objects.deals.read',
                'crm.objects.deals.write',
            ], []);
    }

    #[Route('/callback/hubspot', name: 'app_tool_oauth_hubspot_callback')]
    public function hubspotCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('hubspot_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                // If this was initial setup and user cancelled, remove the temporary config
                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('hubspot_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'HubSpot setup cancelled. HubSpot authorization is required to use this integration.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('hubspot_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

        // Continue with normal flow
        $configId = $request->getSession()->get('hubspot_oauth_config_id');

        if (!$configId) {
            $this->addFlash('error', 'OAuth session expired. Please try again.');
            return $this->redirectToRoute('app_skills');
        }

        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        try {
            // Get the OAuth2 client
            $client = $clientRegistry->getClient('hubspot');

            // Get the access token
            $accessToken = $client->getAccessToken();

            // Store the credentials
            $credentials = [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires(),
            ];

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('hubspot_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'HubSpot integration created and connected successfully!');
            } else {
                $this->addFlash('success', 'HubSpot integration connected successfully!');
            }

            return $this->redirectToRoute('app_skills');
        } catch (\Exception $e) {
            // If this was initial setup and failed, remove the temporary config
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->entityManager->remove($config);
                $this->entityManager->flush();
            }

            $this->addFlash('error', 'Failed to connect to HubSpot: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }

    // ========================================
    // SAP C4C OAuth2 Flow (User Delegation via Azure AD)
    // ========================================

    #[Route('/sap-c4c/start/{configId}', name: 'app_tool_oauth_sap_c4c_start')]
    public function sapC4cStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Verify this is an OAuth2 mode config
        $existingCredentials = [];
        if ($config->getEncryptedCredentials()) {
            $decrypted = $this->encryptionService->decrypt($config->getEncryptedCredentials());
            $existingCredentials = json_decode($decrypted, true) ?: [];
        }

        if (($existingCredentials['auth_mode'] ?? 'basic') !== 'oauth2') {
            $this->addFlash('error', 'This integration is not configured for OAuth2 authentication');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('sap_c4c_oauth_config_id', $configId);

        // Redirect to Azure AD OAuth with appropriate scopes
        // We need offline_access for refresh token
        $scopes = [
            'openid',
            'profile',
            'email',
            'offline_access',
        ];

        return $clientRegistry
            ->getClient('azure_sap_c4c')
            ->redirect($scopes, []);
    }

    #[Route('/callback/sap-c4c', name: 'app_tool_oauth_sap_c4c_callback')]
    public function sapC4cCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('sap_c4c_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('sap_c4c_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'SAP C4C setup cancelled. Azure AD authorization is required for OAuth2 user delegation.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('sap_c4c_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

        if (!$configId) {
            $this->addFlash('error', 'OAuth session expired. Please try again.');
            return $this->redirectToRoute('app_skills');
        }

        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        try {
            // Get the OAuth2 client
            $client = $clientRegistry->getClient('azure_sap_c4c');

            // Get the access token (includes refresh token due to offline_access scope)
            $accessToken = $client->getAccessToken();

            // Get existing credentials to preserve OAuth2 config fields
            $existingCredentials = [];
            if ($config->getEncryptedCredentials()) {
                $decrypted = $this->encryptionService->decrypt($config->getEncryptedCredentials());
                $existingCredentials = json_decode($decrypted, true) ?: [];
            }

            // Extract tenant ID from the access token (JWT) for future API calls
            $azureTenantId = null;
            $tokenParts = explode('.', $accessToken->getToken());
            if (count($tokenParts) >= 2) {
                $payload = json_decode(base64_decode($tokenParts[1]), true);
                $azureTenantId = $payload['tid'] ?? null;
            }

            // Merge Azure OAuth tokens with existing credentials
            $credentials = array_merge($existingCredentials, [
                'azure_access_token' => $accessToken->getToken(),
                'azure_refresh_token' => $accessToken->getRefreshToken(),
                'azure_expires_at' => $accessToken->getExpires(),
                'azure_token_acquired_at' => time(),
                'azure_tenant_id' => $azureTenantId, // Auto-extracted from token
            ]);

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('sap_c4c_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'SAP C4C integration created and connected via Azure AD successfully!');
            } else {
                $this->addFlash('success', 'SAP C4C integration connected via Azure AD successfully!');
            }

            return $this->redirectToRoute('app_skills');
        } catch (\Exception $e) {
            // If this was initial setup and failed, remove the temporary config
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->entityManager->remove($config);
                $this->entityManager->flush();
            }

            $this->addFlash('error', 'Failed to connect to Azure AD: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }

    // ========================================
    // SAP SAC OAuth2 Flow (User Delegation via Azure AD)
    // ========================================

    #[Route('/sap-sac/start/{configId}', name: 'app_tool_oauth_sap_sac_start')]
    public function sapSacStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Verify this is an OAuth2 user delegation mode config
        $existingCredentials = [];
        if ($config->getEncryptedCredentials()) {
            $decrypted = $this->encryptionService->decrypt($config->getEncryptedCredentials());
            $existingCredentials = json_decode($decrypted, true) ?: [];
        }

        if (($existingCredentials['auth_mode'] ?? 'client_credentials') !== 'user_delegation') {
            $this->addFlash('error', 'This integration is not configured for user delegation authentication');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('sap_sac_oauth_config_id', $configId);

        // Redirect to Azure AD OAuth with appropriate scopes
        $scopes = [
            'openid',
            'profile',
            'email',
            'offline_access',
        ];

        return $clientRegistry
            ->getClient('azure_sap_sac')
            ->redirect($scopes, []);
    }

    #[Route('/callback/sap-sac', name: 'app_tool_oauth_sap_sac_callback')]
    public function sapSacCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('sap_sac_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('sap_sac_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'SAP SAC setup cancelled. Azure AD authorization is required for user delegation.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('sap_sac_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

        if (!$configId) {
            $this->addFlash('error', 'OAuth session expired. Please try again.');
            return $this->redirectToRoute('app_skills');
        }

        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        try {
            // Get the OAuth2 client
            $client = $clientRegistry->getClient('azure_sap_sac');

            // Get the access token (includes refresh token due to offline_access scope)
            $accessToken = $client->getAccessToken();

            // Get existing credentials to preserve OAuth2 config fields
            $existingCredentials = [];
            if ($config->getEncryptedCredentials()) {
                $decrypted = $this->encryptionService->decrypt($config->getEncryptedCredentials());
                $existingCredentials = json_decode($decrypted, true) ?: [];
            }

            // Extract tenant ID from the access token (JWT) for future API calls
            $azureTenantId = null;
            $tokenParts = explode('.', $accessToken->getToken());
            if (count($tokenParts) >= 2) {
                $payload = json_decode(base64_decode($tokenParts[1]), true);
                $azureTenantId = $payload['tid'] ?? null;
            }

            // Merge Azure OAuth tokens with existing credentials
            $credentials = array_merge($existingCredentials, [
                'azure_access_token' => $accessToken->getToken(),
                'azure_refresh_token' => $accessToken->getRefreshToken(),
                'azure_expires_at' => $accessToken->getExpires(),
                'azure_token_acquired_at' => time(),
                'azure_tenant_id' => $azureTenantId, // Auto-extracted from token
            ]);

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('sap_sac_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'SAP SAC integration created and connected via Azure AD successfully!');
            } else {
                $this->addFlash('success', 'SAP SAC integration connected via Azure AD successfully!');
            }

            return $this->redirectToRoute('app_skills');
        } catch (\Exception $e) {
            // If this was initial setup and failed, remove the temporary config
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->entityManager->remove($config);
                $this->entityManager->flush();
            }

            $this->addFlash('error', 'Failed to connect to Azure AD: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }

    // ========================================
    // Wrike OAuth2 Flow
    // ========================================

    #[Route('/wrike/start/{configId}', name: 'app_tool_oauth_wrike_start')]
    public function wrikeStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('wrike_oauth_config_id', $configId);

        // Redirect to Wrike OAuth with full access scope
        return $clientRegistry
            ->getClient('wrike')
            ->redirect(['wsReadWrite'], []);
    }

    #[Route('/callback/wrike', name: 'app_tool_oauth_wrike_callback')]
    public function wrikeCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('wrike_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                // If this was initial setup and user cancelled, remove the temporary config
                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('wrike_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'Wrike setup cancelled. Wrike authorization is required to use this integration.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('wrike_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

        // Continue with normal flow
        $configId = $request->getSession()->get('wrike_oauth_config_id');

        if (!$configId) {
            $this->addFlash('error', 'OAuth session expired. Please try again.');
            return $this->redirectToRoute('app_skills');
        }

        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        try {
            // Get the OAuth2 client
            $client = $clientRegistry->getClient('wrike');

            // Get the access token
            $accessToken = $client->getAccessToken();

            // Get the provider to access the host (datacenter-specific URL)
            /** @var \App\OAuth2\WrikeProvider $provider */
            $provider = $client->getOAuth2Provider();
            $host = $provider->getHost() ?? 'www.wrike.com';

            // Store the credentials including the host for API calls
            $credentials = [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires(),
                'host' => $host, // Critical: datacenter-specific API URL
            ];

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('wrike_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'Wrike integration created and connected successfully!');
            } else {
                $this->addFlash('success', 'Wrike integration connected successfully!');
            }

            return $this->redirectToRoute('app_skills');
        } catch (\Exception $e) {
            // If this was initial setup and failed, remove the temporary config
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->entityManager->remove($config);
                $this->entityManager->flush();
            }

            $this->addFlash('error', 'Failed to connect to Wrike: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }

    // ========================================
    // Candis OAuth2 Flow
    // ========================================

    #[Route('/candis/start/{configId}', name: 'app_tool_oauth_candis_start')]
    public function candisStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        // Store the config ID in session for callback
        $request->getSession()->set('candis_oauth_config_id', $configId);

        // Redirect to Candis OAuth with required scopes
        return $clientRegistry
            ->getClient('candis')
            ->redirect(['exports', 'core_data', 'offline_access'], []);
    }

    #[Route('/callback/candis', name: 'app_tool_oauth_candis_callback')]
    public function candisCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get('candis_oauth_config_id');

        // Handle OAuth errors or user cancellation
        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                // If this was initial setup and user cancelled, remove the temporary config
                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove('candis_oauth_config_id');
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', 'Candis setup cancelled. Candis authorization is required to use this integration.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove('candis_oauth_config_id');
            return $this->redirectToRoute('app_skills');
        }

        // Continue with normal flow
        $configId = $request->getSession()->get('candis_oauth_config_id');

        if (!$configId) {
            $this->addFlash('error', 'OAuth session expired. Please try again.');
            return $this->redirectToRoute('app_skills');
        }

        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        try {
            // Get the OAuth2 client
            $client = $clientRegistry->getClient('candis');

            // Get the access token
            $accessToken = $client->getAccessToken();

            // Auto-discover organization by calling /v1/organizations/info
            $orgInfo = $this->httpClient->request(
                'GET',
                'https://api.candis.io/v1/organizations/info',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken->getToken(),
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => 10,
                ]
            )->toArray();

            $organizationId = $orgInfo['id'] ?? null;
            $organizationName = $orgInfo['name'] ?? 'Unknown';

            if (!$organizationId) {
                throw new \RuntimeException('Could not determine Candis organization ID from API response.');
            }

            // Store the credentials including organization info
            $credentials = [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires(),
                'organization_id' => $organizationId,
                'organization_name' => $organizationName,
            ];

            // Encrypt and save credentials
            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            $this->entityManager->flush();

            // Clean up session
            $request->getSession()->remove('candis_oauth_config_id');

            // Check if this was part of initial setup flow
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', 'Candis integration created and connected successfully! Organization: ' . $organizationName);
            } else {
                $this->addFlash('success', 'Candis integration connected successfully! Organization: ' . $organizationName);
            }

            return $this->redirectToRoute('app_skills');
        } catch (\Exception $e) {
            // If this was initial setup and failed, remove the temporary config
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->entityManager->remove($config);
                $this->entityManager->flush();
            }

            $this->addFlash('error', 'Failed to connect to Candis: ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }

    // ========================================
    // Outlook Mail OAuth2 Flow
    // ========================================

    #[Route('/outlook-mail/start/{configId}', name: 'app_tool_oauth_outlook_mail_start')]
    public function outlookMailStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        $request->getSession()->set('outlook_mail_oauth_config_id', $configId);

        return $clientRegistry
            ->getClient('azure_outlook_mail')
            ->redirect([
                'openid',
                'profile',
                'email',
                'offline_access',
                'https://graph.microsoft.com/User.Read',
                'https://graph.microsoft.com/Mail.Read',
            ], []);
    }

    #[Route('/callback/outlook-mail', name: 'app_tool_oauth_outlook_mail_callback')]
    public function outlookMailCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        return $this->handleAzureOAuthCallback(
            $request,
            $clientRegistry,
            'azure_outlook_mail',
            'outlook_mail_oauth_config_id',
            'Outlook Mail'
        );
    }

    // ========================================
    // Outlook Calendar OAuth2 Flow
    // ========================================

    #[Route('/outlook-calendar/start/{configId}', name: 'app_tool_oauth_outlook_calendar_start')]
    public function outlookCalendarStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        $request->getSession()->set('outlook_calendar_oauth_config_id', $configId);

        return $clientRegistry
            ->getClient('azure_outlook_calendar')
            ->redirect([
                'openid',
                'profile',
                'email',
                'offline_access',
                'https://graph.microsoft.com/User.Read',
                'https://graph.microsoft.com/Calendars.Read',
            ], []);
    }

    #[Route('/callback/outlook-calendar', name: 'app_tool_oauth_outlook_calendar_callback')]
    public function outlookCalendarCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        return $this->handleAzureOAuthCallback(
            $request,
            $clientRegistry,
            'azure_outlook_calendar',
            'outlook_calendar_oauth_config_id',
            'Outlook Calendar'
        );
    }

    // ========================================
    // MS Teams OAuth2 Flow
    // ========================================

    #[Route('/teams/start/{configId}', name: 'app_tool_oauth_teams_start')]
    public function teamsStart(int $configId, Request $request, ClientRegistry $clientRegistry): RedirectResponse
    {
        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        $request->getSession()->set('teams_oauth_config_id', $configId);

        return $clientRegistry
            ->getClient('azure_teams')
            ->redirect([
                'openid',
                'profile',
                'email',
                'offline_access',
                'https://graph.microsoft.com/User.Read',
                'https://graph.microsoft.com/Team.ReadBasic.All',
                'https://graph.microsoft.com/Channel.ReadBasic.All',
                'https://graph.microsoft.com/ChannelMessage.Read.All',
                'https://graph.microsoft.com/ChannelMessage.Send',
                'https://graph.microsoft.com/Channel.Create',
                'https://graph.microsoft.com/Chat.Read',
                'https://graph.microsoft.com/ChatMessage.Send',
            ], []);
    }

    #[Route('/callback/teams', name: 'app_tool_oauth_teams_callback')]
    public function teamsCallback(Request $request, ClientRegistry $clientRegistry): Response
    {
        return $this->handleAzureOAuthCallback(
            $request,
            $clientRegistry,
            'azure_teams',
            'teams_oauth_config_id',
            'MS Teams'
        );
    }

    /**
     * Shared Azure OAuth callback handler for Outlook Mail, Outlook Calendar, and MS Teams.
     */
    private function handleAzureOAuthCallback(
        Request $request,
        ClientRegistry $clientRegistry,
        string $oauthClientName,
        string $sessionKey,
        string $integrationLabel
    ): Response {
        $error = $request->query->get('error');
        $configId = $request->getSession()->get($sessionKey);

        if ($error) {
            if ($configId) {
                $tempConfig = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);
                $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');

                if ($tempConfig && $oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                    $request->getSession()->remove('oauth_flow_integration');
                    $request->getSession()->remove($sessionKey);
                    $this->entityManager->remove($tempConfig);
                    $this->entityManager->flush();

                    if ($error === 'access_denied') {
                        $this->addFlash('warning', $integrationLabel . ' setup cancelled. Microsoft authorization is required to use this integration.');
                    } else {
                        $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                    }
                } else {
                    $this->addFlash('error', 'Authorization failed: ' . $request->query->get('error_description', $error));
                }
            }

            $request->getSession()->remove($sessionKey);
            return $this->redirectToRoute('app_skills');
        }

        if (!$configId) {
            $this->addFlash('error', 'OAuth session expired. Please try again.');
            return $this->redirectToRoute('app_skills');
        }

        $config = $this->entityManager->getRepository(IntegrationConfig::class)->find($configId);

        if (!$config || $config->getUser() !== $this->getUser()) {
            $this->addFlash('error', 'Integration configuration not found');
            return $this->redirectToRoute('app_skills');
        }

        try {
            $client = $clientRegistry->getClient($oauthClientName);
            $accessToken = $client->getAccessToken();

            $existingCredentials = [];
            if ($config->getEncryptedCredentials()) {
                $existingCredentials = json_decode(
                    $this->encryptionService->decrypt($config->getEncryptedCredentials()),
                    true
                ) ?: [];
            }

            $credentials = array_merge($existingCredentials, [
                'access_token' => $accessToken->getToken(),
                'refresh_token' => $accessToken->getRefreshToken(),
                'expires_at' => $accessToken->getExpires(),
                'tenant_id' => $accessToken->getValues()['tenant_id'] ?? 'common',
                'client_id' => $_ENV['AZURE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['AZURE_CLIENT_SECRET'] ?? '',
            ]);

            $config->setEncryptedCredentials(
                $this->encryptionService->encrypt(json_encode($credentials))
            );
            $config->setActive(true);

            $this->entityManager->flush();

            $request->getSession()->remove($sessionKey);

            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->addFlash('success', $integrationLabel . ' integration created and connected successfully!');
            } else {
                $this->addFlash('success', $integrationLabel . ' integration connected successfully!');
            }

            return $this->redirectToRoute('app_skills');
        } catch (\Exception $e) {
            $oauthFlowIntegration = $request->getSession()->get('oauth_flow_integration');
            if ($oauthFlowIntegration && $oauthFlowIntegration == $configId) {
                $request->getSession()->remove('oauth_flow_integration');
                $this->entityManager->remove($config);
                $this->entityManager->flush();
            }

            $this->addFlash('error', 'Failed to connect to ' . $integrationLabel . ': ' . $e->getMessage());
            return $this->redirectToRoute('app_skills');
        }
    }

    /**
     * Resolve the actual Azure AD tenant GUID from a SharePoint URL
     * using Microsoft's public OpenID discovery endpoint.
     *
     * The SharePoint subdomain (e.g. "valanticmore") does NOT always match
     * the Azure AD tenant name, so we cannot use it directly. Instead we query
     * login.microsoftonline.com/{host}/.well-known/openid-configuration
     * which returns URLs containing the real tenant GUID.
     */
    private function resolveTenantFromSharePointUrl(string $sharepointUrl): ?string
    {
        // Normalise: strip protocol and trailing path/slashes
        $host = preg_replace('#^https?://#i', '', trim($sharepointUrl));
        $host = rtrim($host, '/');
        $host = explode('/', $host)[0]; // strip any path

        // Extract subdomain from sharepoint URL (e.g. "valanticmore" from "valanticmore.sharepoint.com")
        if (!preg_match('/^([a-z0-9-]+)\.sharepoint\.com$/i', $host, $matches)) {
            return null;
        }

        // The OpenID discovery endpoint requires .onmicrosoft.com, not .sharepoint.com
        $onMicrosoftDomain = $matches[1] . '.onmicrosoft.com';
        $discoveryUrl = "https://login.microsoftonline.com/{$onMicrosoftDomain}/.well-known/openid-configuration";

        try {
            $response = $this->httpClient->request('GET', $discoveryUrl, ['timeout' => 10]);
            $data = $response->toArray();

            // Extract tenant GUID from token_endpoint:
            // https://login.microsoftonline.com/{guid}/oauth2/token
            if (isset($data['token_endpoint']) && preg_match('#/([a-f0-9-]{36})/#', $data['token_endpoint'], $m)) {
                return $m[1];
            }
        } catch (\Exception $e) {
            error_log('Failed to resolve Azure AD tenant from SharePoint URL "' . $sharepointUrl . '": ' . $e->getMessage());
        }

        return null;
    }
}
