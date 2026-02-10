<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OutlookMailService
{
    private const GRAPH_API_BASE = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private HttpClientInterface $httpClient
    ) {
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me', [
                'auth_bearer' => $credentials['access_token'],
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            error_log('Outlook Mail test connection failed: ' . $e->getMessage());
            return false;
        }
    }

    public function searchMessages(array $credentials, string $query, ?string $folder = null, int $limit = 25): array
    {
        try {
            $endpoint = $folder
                ? self::GRAPH_API_BASE . '/me/mailFolders/' . urlencode($folder) . '/messages'
                : self::GRAPH_API_BASE . '/me/messages';

            $queryParams = [
                '$search' => '"' . $query . '"',
                '$top' => min($limit, 50),
                '$select' => 'id,subject,from,toRecipients,receivedDateTime,bodyPreview,hasAttachments,isRead,importance',
                '$orderby' => 'receivedDateTime desc',
            ];

            $response = $this->httpClient->request('GET', $endpoint, [
                'auth_bearer' => $credentials['access_token'],
                'query' => $queryParams,
            ]);

            $data = $response->toArray();
            $messages = [];

            foreach ($data['value'] ?? [] as $msg) {
                $messages[] = [
                    'id' => $msg['id'],
                    'subject' => $msg['subject'] ?? '(No Subject)',
                    'from' => $msg['from']['emailAddress']['name'] ?? $msg['from']['emailAddress']['address'] ?? 'Unknown',
                    'fromEmail' => $msg['from']['emailAddress']['address'] ?? '',
                    'toRecipients' => array_map(
                        fn($r) => $r['emailAddress']['name'] ?? $r['emailAddress']['address'] ?? '',
                        $msg['toRecipients'] ?? []
                    ),
                    'receivedDateTime' => $msg['receivedDateTime'] ?? '',
                    'bodyPreview' => $msg['bodyPreview'] ?? '',
                    'hasAttachments' => $msg['hasAttachments'] ?? false,
                    'isRead' => $msg['isRead'] ?? false,
                    'importance' => $msg['importance'] ?? 'normal',
                ];
            }

            return [
                'results' => $messages,
                'count' => count($messages),
                'query' => $query,
            ];
        } catch (\Exception $e) {
            error_log('Outlook Mail search failed: ' . $e->getMessage());
            return ['error' => 'Search failed: ' . $e->getMessage(), 'results' => [], 'count' => 0];
        }
    }

    public function getMessage(array $credentials, string $messageId): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/messages/' . urlencode($messageId), [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$select' => 'id,subject,from,toRecipients,ccRecipients,bccRecipients,receivedDateTime,sentDateTime,body,hasAttachments,importance,isRead,conversationId,internetMessageId',
                    '$expand' => 'attachments($select=id,name,contentType,size)',
                ],
            ]);

            $msg = $response->toArray();

            $bodyContent = $msg['body']['content'] ?? '';
            if (($msg['body']['contentType'] ?? '') === 'html') {
                $bodyContent = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $bodyContent));
                $bodyContent = preg_replace('/\n{3,}/', "\n\n", trim($bodyContent));
            }

            return [
                'id' => $msg['id'],
                'subject' => $msg['subject'] ?? '(No Subject)',
                'from' => [
                    'name' => $msg['from']['emailAddress']['name'] ?? '',
                    'email' => $msg['from']['emailAddress']['address'] ?? '',
                ],
                'toRecipients' => array_map(
                    fn($r) => ['name' => $r['emailAddress']['name'] ?? '', 'email' => $r['emailAddress']['address'] ?? ''],
                    $msg['toRecipients'] ?? []
                ),
                'ccRecipients' => array_map(
                    fn($r) => ['name' => $r['emailAddress']['name'] ?? '', 'email' => $r['emailAddress']['address'] ?? ''],
                    $msg['ccRecipients'] ?? []
                ),
                'receivedDateTime' => $msg['receivedDateTime'] ?? '',
                'sentDateTime' => $msg['sentDateTime'] ?? '',
                'body' => $bodyContent,
                'hasAttachments' => $msg['hasAttachments'] ?? false,
                'attachments' => array_map(
                    fn($a) => ['id' => $a['id'], 'name' => $a['name'], 'contentType' => $a['contentType'], 'size' => $a['size']],
                    $msg['attachments'] ?? []
                ),
                'importance' => $msg['importance'] ?? 'normal',
                'isRead' => $msg['isRead'] ?? false,
                'conversationId' => $msg['conversationId'] ?? null,
            ];
        } catch (\Exception $e) {
            error_log('Outlook Mail get message failed: ' . $e->getMessage());
            return ['error' => 'Failed to get message: ' . $e->getMessage()];
        }
    }

    public function listFolders(array $credentials): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/mailFolders', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$top' => 50,
                    '$select' => 'id,displayName,totalItemCount,unreadItemCount,parentFolderId',
                ],
            ]);

            $data = $response->toArray();
            $folders = [];

            foreach ($data['value'] ?? [] as $folder) {
                $folders[] = [
                    'id' => $folder['id'],
                    'displayName' => $folder['displayName'] ?? '',
                    'totalItemCount' => $folder['totalItemCount'] ?? 0,
                    'unreadItemCount' => $folder['unreadItemCount'] ?? 0,
                ];
            }

            return ['folders' => $folders, 'count' => count($folders)];
        } catch (\Exception $e) {
            error_log('Outlook Mail list folders failed: ' . $e->getMessage());
            return ['error' => 'Failed to list folders: ' . $e->getMessage(), 'folders' => []];
        }
    }

    public function listMessages(array $credentials, string $folderId, int $limit = 25, int $skip = 0): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/mailFolders/' . urlencode($folderId) . '/messages', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$top' => min($limit, 50),
                    '$skip' => $skip,
                    '$select' => 'id,subject,from,toRecipients,receivedDateTime,bodyPreview,hasAttachments,isRead,importance',
                    '$orderby' => 'receivedDateTime desc',
                ],
            ]);

            $data = $response->toArray();
            $messages = [];

            foreach ($data['value'] ?? [] as $msg) {
                $messages[] = [
                    'id' => $msg['id'],
                    'subject' => $msg['subject'] ?? '(No Subject)',
                    'from' => $msg['from']['emailAddress']['name'] ?? $msg['from']['emailAddress']['address'] ?? 'Unknown',
                    'fromEmail' => $msg['from']['emailAddress']['address'] ?? '',
                    'receivedDateTime' => $msg['receivedDateTime'] ?? '',
                    'bodyPreview' => $msg['bodyPreview'] ?? '',
                    'hasAttachments' => $msg['hasAttachments'] ?? false,
                    'isRead' => $msg['isRead'] ?? false,
                    'importance' => $msg['importance'] ?? 'normal',
                ];
            }

            return [
                'results' => $messages,
                'count' => count($messages),
                'folderId' => $folderId,
                'skip' => $skip,
                'hasMore' => isset($data['@odata.nextLink']),
            ];
        } catch (\Exception $e) {
            error_log('Outlook Mail list messages failed: ' . $e->getMessage());
            return ['error' => 'Failed to list messages: ' . $e->getMessage(), 'results' => [], 'count' => 0];
        }
    }

    public function refreshToken(string $refreshToken, string $clientId, string $clientSecret, string $tenantId): array
    {
        $response = $this->httpClient->request('POST', "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
            'body' => [
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'scope' => 'openid profile email offline_access https://graph.microsoft.com/Mail.Read https://graph.microsoft.com/User.Read',
            ],
        ]);

        $data = $response->toArray();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_at' => time() + ($data['expires_in'] ?? 3600),
        ];
    }
}
