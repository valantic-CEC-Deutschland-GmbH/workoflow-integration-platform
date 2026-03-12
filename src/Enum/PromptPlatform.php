<?php

namespace App\Enum;

enum PromptPlatform: string
{
    case CHATGPT = 'chatgpt';
    case CLAUDE_DESKTOP = 'claude_desktop';
    case CLAUDE_CODE = 'claude_code';
    case CLAUDE_FOR_EXCEL = 'claude_for_excel';
    case CLAUDE_FOR_POWERPOINT = 'claude_for_powerpoint';
    case CODEX = 'codex';
    case CONFLUENCE_AI = 'confluence_ai';
    case CONTINUOUS_CI = 'continuous_ci';
    case COPILOT = 'copilot';
    case CURSOR = 'cursor';
    case FIGMA_AI = 'figma_ai';
    case GEMINI = 'gemini';
    case GEMINI_CLI = 'gemini_cli';
    case GITHUB_COPILOT = 'github_copilot';
    case GITLAB_DUO = 'gitlab_duo';
    case JIRA_AI = 'jira_ai';
    case NOTEBOOK_LM = 'notebook_lm';
    case PERPLEXITY = 'perplexity';
    case VALLY = 'vally';
    case WINDSURF = 'windsurf';
    case WORKOFLOW = 'workoflow';

    public function label(): string
    {
        return match ($this) {
            self::CHATGPT => 'ChatGPT',
            self::CLAUDE_DESKTOP => 'Claude Desktop (Chat, Cowork, Code)',
            self::CLAUDE_CODE => 'Claude Code',
            self::CLAUDE_FOR_EXCEL => 'Claude for Excel',
            self::CLAUDE_FOR_POWERPOINT => 'Claude for PowerPoint',
            self::CODEX => 'Codex',
            self::CONFLUENCE_AI => 'Confluence AI',
            self::CONTINUOUS_CI => 'Continuous CI (Autonomous AI Agents)',
            self::COPILOT => 'Copilot (Microsoft 365)',
            self::CURSOR => 'Cursor',
            self::FIGMA_AI => 'Figma AI',
            self::GEMINI => 'Gemini',
            self::GEMINI_CLI => 'Gemini CLI',
            self::GITHUB_COPILOT => 'GitHub Copilot',
            self::GITLAB_DUO => 'GitLab Duo',
            self::JIRA_AI => 'Jira AI (Rovo)',
            self::NOTEBOOK_LM => 'NotebookLM',
            self::PERPLEXITY => 'Perplexity',
            self::VALLY => 'Vally (valantic Chat UI)',
            self::WINDSURF => 'Windsurf',
            self::WORKOFLOW => 'Workoflow',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->label()] = $case->value;
        }

        return $choices;
    }
}
