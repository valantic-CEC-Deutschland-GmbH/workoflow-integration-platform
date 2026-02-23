<?php

namespace App\Integration\UserIntegrations;

use App\Entity\IntegrationConfig;
use App\Integration\PersonalizedSkillInterface;
use App\Integration\ToolCategory;
use App\Integration\ToolDefinition;
use App\Integration\CredentialField;
use App\Service\Integration\TrelloService;
use Twig\Environment;

class TrelloIntegration implements PersonalizedSkillInterface
{
    public function __construct(
        private TrelloService $trelloService,
        private Environment $twig
    ) {
    }

    public function getType(): string
    {
        return 'trello';
    }

    public function getName(): string
    {
        return 'Trello';
    }

    public function getTools(): array
    {
        return [
            new ToolDefinition(
                'trello_search',
                'Search across all Trello boards, cards, and lists using a query string. Returns: Object with arrays of boards, cards, and lists matching the search term. Each board includes id, name, url, shortUrl, desc, closed. Each card includes id, name, idList, idBoard, desc, url, shortUrl, closed, pos. Each list includes id, name, idBoard, closed, pos.',
                [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Search query to find boards, cards, or lists'
                    ],
                    [
                        'name' => 'maxResults',
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Maximum number of card results to return (default: 50)'
                    ]
                ]
            ),
            new ToolDefinition(
                'trello_get_boards',
                'Get all Trello boards accessible to the authenticated user. Returns: Array of boards, each with id, name, url, shortUrl, desc, closed, idOrganization, pinned, starred, prefs, labelNames.',
                []
            ),
            new ToolDefinition(
                'trello_get_board',
                'Get detailed information about a specific Trello board including its name, description, and URL. Returns: Board object with id, name, desc, url, shortUrl, closed, idOrganization, pinned, starred, prefs, labelNames, limits, memberships.',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Board ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'trello_get_board_lists',
                'Get all lists on a specific Trello board. Lists are containers that hold cards (e.g., "To Do", "In Progress", "Done"). Returns: Array of lists, each with id, name, closed, pos, softLimit, idBoard, subscribed, limits.',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Board ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'trello_get_board_cards',
                'Get all cards on a specific Trello board across all lists. Useful for analyzing the entire board content. Returns: Array of cards, each with id, name, desc, url, shortUrl, idList, idBoard, idMembers, idLabels, labels, due, dueComplete, closed, pos, badges, dateLastActivity.',
                [
                    [
                        'name' => 'boardId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Board ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'trello_get_list_cards',
                'Get all cards in a specific list. Use this to see cards in a particular column/list on a board. Returns: Array of cards, each with id, name, desc, url, shortUrl, idList, idBoard, idMembers, idLabels, labels, due, dueComplete, closed, pos, badges, dateLastActivity.',
                [
                    [
                        'name' => 'listId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'List ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'trello_get_card',
                'Get detailed information about a specific card including title, description, members, labels, attachments, and checklists. Returns: Card object with id, name, desc, url, shortUrl, idList, idBoard, idMembers, idLabels, labels, due, dueComplete, closed, pos, badges (with attachments, comments, checkItems counts), dateLastActivity, idChecklists, cover.',
                [
                    [
                        'name' => 'cardId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Card ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'trello_get_card_comments',
                'Get all comments on a specific Trello card. Returns comment text, author, and timestamp. Returns: Array of action objects with type "commentCard", each containing id, idMemberCreator, date, data (with text, card, board, list info), memberCreator (with id, username, fullName, avatarUrl).',
                [
                    [
                        'name' => 'cardId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Card ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'trello_get_card_checklists',
                'Get all checklists and checklist items on a specific card. Shows todo items with their completion status. Returns: Array of checklist objects, each with id, name, idCard, pos, checkItems (array with id, name, state, pos, due, idMember).',
                [
                    [
                        'name' => 'cardId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Card ID'
                    ]
                ]
            ),
            new ToolDefinition(
                'trello_create_card',
                'Create a new card in a specific list. You can set title, description, due date, members, and labels. Returns: Created card object with id, name, desc, url, shortUrl, idList, idBoard, idMembers, idLabels, labels, due, dueComplete, closed, pos, badges, dateLastActivity, shortLink.',
                [
                    [
                        'name' => 'idList',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'List ID where the card should be created'
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Card title/name'
                    ],
                    [
                        'name' => 'desc',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Card description (optional)'
                    ],
                    [
                        'name' => 'pos',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Position in the list: "top", "bottom", or a positive number (optional)'
                    ],
                    [
                        'name' => 'due',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Due date in ISO format (e.g., 2024-12-31T23:59:59.000Z) (optional)'
                    ],
                    [
                        'name' => 'idMembers',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated list of member IDs to assign to the card (optional)'
                    ],
                    [
                        'name' => 'idLabels',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated list of label IDs to add to the card (optional)'
                    ]
                ],
                ToolCategory::WRITE
            ),
            new ToolDefinition(
                'trello_update_card',
                'Update an existing Trello card. Can modify title, description, due date, members, labels, position, or close/archive the card. Returns: Updated card object with id, name, desc, url, shortUrl, idList, idBoard, idMembers, idLabels, labels, due, dueComplete, closed, pos, badges, dateLastActivity.',
                [
                    [
                        'name' => 'cardId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Card ID to update'
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New card title/name (optional)'
                    ],
                    [
                        'name' => 'desc',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New card description (optional)'
                    ],
                    [
                        'name' => 'closed',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Whether the card is closed/archived (optional)'
                    ],
                    [
                        'name' => 'idList',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Move card to a different list by providing the list ID (optional)'
                    ],
                    [
                        'name' => 'pos',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'New position: "top", "bottom", or a positive number (optional)'
                    ],
                    [
                        'name' => 'due',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Due date in ISO format (optional)'
                    ],
                    [
                        'name' => 'dueComplete',
                        'type' => 'boolean',
                        'required' => false,
                        'description' => 'Whether the due date is marked as complete (optional)'
                    ],
                    [
                        'name' => 'idMembers',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated list of member IDs (replaces existing members) (optional)'
                    ],
                    [
                        'name' => 'idLabels',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Comma-separated list of label IDs (replaces existing labels) (optional)'
                    ]
                ],
                ToolCategory::WRITE
            ),
            new ToolDefinition(
                'trello_add_comment',
                'Add a comment to a Trello card. Comments are visible to all board members. Returns: Action object with id, type "commentCard", idMemberCreator, date, data (with text, card, board, list info), memberCreator (with id, username, fullName).',
                [
                    [
                        'name' => 'cardId',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Card ID'
                    ],
                    [
                        'name' => 'text',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Comment text'
                    ]
                ],
                ToolCategory::WRITE
            )
        ];
    }

    public function executeTool(string $toolName, array $parameters, ?array $credentials = null): array
    {
        if (!$credentials) {
            throw new \InvalidArgumentException('Trello integration requires credentials');
        }

        return match ($toolName) {
            'trello_search' => $this->trelloService->search(
                $credentials,
                $parameters['query'],
                $parameters['maxResults'] ?? 50
            ),
            'trello_get_boards' => $this->trelloService->getBoards($credentials),
            'trello_get_board' => $this->trelloService->getBoard(
                $credentials,
                $parameters['boardId']
            ),
            'trello_get_board_lists' => $this->trelloService->getBoardLists(
                $credentials,
                $parameters['boardId']
            ),
            'trello_get_board_cards' => $this->trelloService->getBoardCards(
                $credentials,
                $parameters['boardId']
            ),
            'trello_get_list_cards' => $this->trelloService->getListCards(
                $credentials,
                $parameters['listId']
            ),
            'trello_get_card' => $this->trelloService->getCard(
                $credentials,
                $parameters['cardId']
            ),
            'trello_get_card_comments' => $this->trelloService->getCardComments(
                $credentials,
                $parameters['cardId']
            ),
            'trello_get_card_checklists' => $this->trelloService->getCardChecklists(
                $credentials,
                $parameters['cardId']
            ),
            'trello_create_card' => $this->trelloService->createCard(
                $credentials,
                $parameters
            ),
            'trello_update_card' => $this->trelloService->updateCard(
                $credentials,
                $parameters['cardId'],
                $parameters
            ),
            'trello_add_comment' => $this->trelloService->addComment(
                $credentials,
                $parameters['cardId'],
                $parameters['text']
            ),
            default => throw new \InvalidArgumentException("Unknown tool: $toolName")
        };
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function validateCredentials(array $credentials): bool
    {
        // Check if all required fields are present and not empty
        if (empty($credentials['api_key']) || empty($credentials['api_token'])) {
            return false;
        }

        // Use TrelloService to validate credentials
        try {
            return $this->trelloService->testConnection($credentials);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getCredentialFields(): array
    {
        return [
            new CredentialField(
                'api_key',
                'text',
                'API Key',
                'Your Trello API Key',
                true,
                'Your Trello API Key. <a href="https://trello.com/app-key" target="_blank" rel="noopener">Get your API Key</a>'
            ),
            new CredentialField(
                'api_token',
                'password',
                'API Token',
                null,
                true,
                'Your Trello API Token. After getting your API Key, click the "Token" link on the same page to generate a token.'
            ),
        ];
    }

    public function getSystemPrompt(?IntegrationConfig $config = null): string
    {
        return $this->twig->render('skills/prompts/trello_full.xml.twig', [
            'api_base_url' => $_ENV['APP_URL'] ?? 'https://subscribe-workflows.vcec.cloud',
            'tool_count' => count($this->getTools()),
            'integration_id' => $config?->getId() ?? 'XXX',
        ]);
    }
    public function isExperimental(): bool
    {
        return false;
    }

    public function getSetupInstructions(): ?string
    {
        return null;
    }

    public function getLogoPath(): string
    {
        return '/images/logos/trello-logo.png';
    }
}
