<?php

namespace App\Service\ScheduledTask;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\MarkdownConverter;

class MsTeamsResponseRenderer implements ResponseRendererInterface
{
    private MarkdownConverter $markdownConverter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => true,
            'external_link' => [
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new ExternalLinkExtension());

        $this->markdownConverter = new MarkdownConverter($environment);
    }

    public function supports(string $tenantType): bool
    {
        return $tenantType === 'ms_teams';
    }

    public function render(string $rawOutput): string
    {
        $text = $this->extractOutputText($rawOutput);

        if ($text === null) {
            return '<pre>' . htmlspecialchars($rawOutput) . '</pre>';
        }

        return $this->markdownConverter->convert($text)->getContent();
    }

    private function extractOutputText(string $rawOutput): ?string
    {
        $outer = json_decode($rawOutput, true);
        if (!is_array($outer)) {
            return null;
        }

        $innerJson = $outer['output'] ?? null;
        if (!is_string($innerJson)) {
            return null;
        }

        // The output field is often double-encoded JSON
        $inner = json_decode($innerJson, true);
        if (is_array($inner) && isset($inner['output'])) {
            return (string) $inner['output'];
        }

        // If inner decode fails, use the string directly
        return $innerJson;
    }
}
