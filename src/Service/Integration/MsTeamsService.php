<?php

namespace App\Service\Integration;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class MsTeamsService
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
            error_log('MS Teams test connection failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @return array{success: bool, message: string, details: string, suggestion: string, tested_endpoints: array<int, array<string, mixed>>}
     */
    public function testConnectionDetailed(array $credentials): array
    {
        $testedEndpoints = [];

        try {
            // Test 1: Basic authentication via /me
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me', [
                'auth_bearer' => $credentials['access_token'],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $testedEndpoints[] = [
                'endpoint' => '/me',
                'status' => $statusCode === 200 ? 'success' : 'failed',
                'http_code' => $statusCode,
            ];

            if ($statusCode !== 200) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed',
                    'details' => 'Could not authenticate with Microsoft Graph API (HTTP ' . $statusCode . ').',
                    'suggestion' => 'Try reconnecting your MS Teams integration via OAuth.',
                    'tested_endpoints' => $testedEndpoints,
                ];
            }

            // Test 2: Verify Teams scope by accessing joinedTeams
            $teamsResponse = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/joinedTeams', [
                'auth_bearer' => $credentials['access_token'],
                'query' => ['$top' => 1],
                'timeout' => 10,
            ]);

            $teamsStatusCode = $teamsResponse->getStatusCode();
            $testedEndpoints[] = [
                'endpoint' => '/me/joinedTeams',
                'status' => $teamsStatusCode === 200 ? 'success' : 'failed',
                'http_code' => $teamsStatusCode,
            ];

            if ($teamsStatusCode !== 200) {
                return [
                    'success' => false,
                    'message' => 'Missing Teams permissions',
                    'details' => 'Authentication works but Teams scopes are not granted (HTTP ' . $teamsStatusCode . ').',
                    'suggestion' => 'Reconnect this integration to request the correct Teams permissions. Some Teams scopes require IT admin consent in Azure Portal.',
                    'tested_endpoints' => $testedEndpoints,
                ];
            }

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => 'Successfully connected to Microsoft Graph API. Teams access verified.',
                'suggestion' => '',
                'tested_endpoints' => $testedEndpoints,
            ];
        } catch (\Exception $e) {
            $testedEndpoints[] = [
                'endpoint' => 'unknown',
                'status' => 'error',
                'http_code' => 0,
            ];

            return [
                'success' => false,
                'message' => 'Connection failed',
                'details' => 'Could not reach Microsoft Graph API: ' . $e->getMessage(),
                'suggestion' => 'Check your network connection or try reconnecting the integration.',
                'tested_endpoints' => $testedEndpoints,
            ];
        }
    }

    public function listTeams(array $credentials): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/joinedTeams', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$select' => 'id,displayName,description',
                ],
            ]);

            $data = $response->toArray();
            $teams = [];

            foreach ($data['value'] ?? [] as $team) {
                $teams[] = [
                    'id' => $team['id'],
                    'displayName' => $team['displayName'] ?? '',
                    'description' => $team['description'] ?? '',
                ];
            }

            return ['teams' => $teams, 'count' => count($teams)];
        } catch (\Exception $e) {
            error_log('MS Teams list teams failed: ' . $e->getMessage());
            return ['error' => 'Failed to list teams: ' . $e->getMessage(), 'teams' => [], 'count' => 0];
        }
    }

    public function listChannels(array $credentials, string $teamId): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/teams/' . urlencode($teamId) . '/channels', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$select' => 'id,displayName,description,membershipType',
                ],
            ]);

            $data = $response->toArray();
            $channels = [];

            foreach ($data['value'] ?? [] as $channel) {
                $channels[] = [
                    'id' => $channel['id'],
                    'displayName' => $channel['displayName'] ?? '',
                    'description' => $channel['description'] ?? '',
                    'membershipType' => $channel['membershipType'] ?? 'standard',
                ];
            }

            return ['channels' => $channels, 'count' => count($channels), 'teamId' => $teamId];
        } catch (\Exception $e) {
            error_log('MS Teams list channels failed: ' . $e->getMessage());
            return ['error' => 'Failed to list channels: ' . $e->getMessage(), 'channels' => [], 'count' => 0];
        }
    }

    public function readChannelMessages(array $credentials, string $teamId, string $channelId, int $limit = 25): array
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                self::GRAPH_API_BASE . '/teams/' . urlencode($teamId) . '/channels/' . urlencode($channelId) . '/messages',
                [
                    'auth_bearer' => $credentials['access_token'],
                    'query' => [
                        '$top' => min($limit, 50),
                    ],
                ]
            );

            $data = $response->toArray();

            return [
                'messages' => array_map(fn($msg) => $this->formatMessage($msg), $data['value'] ?? []),
                'count' => count($data['value'] ?? []),
                'teamId' => $teamId,
                'channelId' => $channelId,
            ];
        } catch (\Exception $e) {
            error_log('MS Teams read channel messages failed: ' . $e->getMessage());
            return ['error' => 'Failed to read messages: ' . $e->getMessage(), 'messages' => [], 'count' => 0];
        }
    }

    public function sendChannelMessage(array $credentials, string $teamId, string $channelId, string $message, string $contentType = 'text'): array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                self::GRAPH_API_BASE . '/teams/' . urlencode($teamId) . '/channels/' . urlencode($channelId) . '/messages',
                [
                    'auth_bearer' => $credentials['access_token'],
                    'json' => [
                        'body' => [
                            'contentType' => $contentType,
                            'content' => $message,
                        ],
                    ],
                ]
            );

            $data = $response->toArray();

            return [
                'success' => true,
                'messageId' => $data['id'] ?? '',
                'createdDateTime' => $data['createdDateTime'] ?? '',
            ];
        } catch (\Exception $e) {
            error_log('MS Teams send channel message failed: ' . $e->getMessage());
            return ['error' => 'Failed to send message: ' . $e->getMessage(), 'success' => false];
        }
    }

    public function createChannel(array $credentials, string $teamId, string $displayName, ?string $description = null): array
    {
        try {
            $body = [
                'displayName' => $displayName,
                'membershipType' => 'standard',
            ];

            if ($description) {
                $body['description'] = $description;
            }

            $response = $this->httpClient->request(
                'POST',
                self::GRAPH_API_BASE . '/teams/' . urlencode($teamId) . '/channels',
                [
                    'auth_bearer' => $credentials['access_token'],
                    'json' => $body,
                ]
            );

            $data = $response->toArray();

            return [
                'success' => true,
                'id' => $data['id'] ?? '',
                'displayName' => $data['displayName'] ?? '',
                'description' => $data['description'] ?? '',
            ];
        } catch (\Exception $e) {
            error_log('MS Teams create channel failed: ' . $e->getMessage());
            return ['error' => 'Failed to create channel: ' . $e->getMessage(), 'success' => false];
        }
    }

    public function listChats(array $credentials, int $limit = 25): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/chats', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$top' => min($limit, 50),
                    '$expand' => 'members($select=displayName,email)',
                    '$select' => 'id,topic,chatType,createdDateTime,lastUpdatedDateTime',
                    '$orderby' => 'lastUpdatedDateTime desc',
                ],
            ]);

            $data = $response->toArray();
            $chats = [];

            foreach ($data['value'] ?? [] as $chat) {
                $members = array_map(fn($m) => [
                    'displayName' => $m['displayName'] ?? '',
                    'email' => $m['email'] ?? '',
                ], $chat['members'] ?? []);

                $chats[] = [
                    'id' => $chat['id'],
                    'topic' => $chat['topic'] ?? '',
                    'chatType' => $chat['chatType'] ?? '',
                    'createdDateTime' => $chat['createdDateTime'] ?? '',
                    'lastUpdatedDateTime' => $chat['lastUpdatedDateTime'] ?? '',
                    'members' => $members,
                ];
            }

            return ['chats' => $chats, 'count' => count($chats)];
        } catch (\Exception $e) {
            error_log('MS Teams list chats failed: ' . $e->getMessage());
            return ['error' => 'Failed to list chats: ' . $e->getMessage(), 'chats' => [], 'count' => 0];
        }
    }

    public function readChatMessages(array $credentials, string $chatId, int $limit = 25): array
    {
        try {
            $response = $this->httpClient->request('GET', self::GRAPH_API_BASE . '/me/chats/' . urlencode($chatId) . '/messages', [
                'auth_bearer' => $credentials['access_token'],
                'query' => [
                    '$top' => min($limit, 50),
                ],
            ]);

            $data = $response->toArray();

            return [
                'messages' => array_map(fn($msg) => $this->formatMessage($msg), $data['value'] ?? []),
                'count' => count($data['value'] ?? []),
                'chatId' => $chatId,
            ];
        } catch (\Exception $e) {
            error_log('MS Teams read chat messages failed: ' . $e->getMessage());
            return ['error' => 'Failed to read chat messages: ' . $e->getMessage(), 'messages' => [], 'count' => 0];
        }
    }

    public function sendChatMessage(array $credentials, string $chatId, string $message, string $contentType = 'text'): array
    {
        try {
            $response = $this->httpClient->request('POST', self::GRAPH_API_BASE . '/me/chats/' . urlencode($chatId) . '/messages', [
                'auth_bearer' => $credentials['access_token'],
                'json' => [
                    'body' => [
                        'contentType' => $contentType,
                        'content' => $message,
                    ],
                ],
            ]);

            $data = $response->toArray();

            return [
                'success' => true,
                'messageId' => $data['id'] ?? '',
                'createdDateTime' => $data['createdDateTime'] ?? '',
            ];
        } catch (\Exception $e) {
            error_log('MS Teams send chat message failed: ' . $e->getMessage());
            return ['error' => 'Failed to send chat message: ' . $e->getMessage(), 'success' => false];
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
                'scope' => 'openid profile email offline_access https://graph.microsoft.com/Team.ReadBasic.All https://graph.microsoft.com/Channel.ReadBasic.All https://graph.microsoft.com/ChannelMessage.Read.All https://graph.microsoft.com/ChannelMessage.Send https://graph.microsoft.com/Channel.Create https://graph.microsoft.com/Chat.Read https://graph.microsoft.com/ChatMessage.Send https://graph.microsoft.com/User.Read',
            ],
        ]);

        $data = $response->toArray();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refreshToken,
            'expires_at' => time() + ($data['expires_in'] ?? 3600),
        ];
    }

    private function formatMessage(array $msg): array
    {
        $bodyContent = $msg['body']['content'] ?? '';
        if (($msg['body']['contentType'] ?? '') === 'html') {
            $bodyContent = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $bodyContent));
            $bodyContent = preg_replace('/\n{3,}/', "\n\n", trim($bodyContent));
        }

        return [
            'id' => $msg['id'] ?? '',
            'from' => $msg['from']['user']['displayName'] ?? $msg['from']['application']['displayName'] ?? 'Unknown',
            'body' => $bodyContent,
            'createdDateTime' => $msg['createdDateTime'] ?? '',
            'messageType' => $msg['messageType'] ?? '',
        ];
    }
}
