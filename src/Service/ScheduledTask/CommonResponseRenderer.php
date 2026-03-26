<?php

namespace App\Service\ScheduledTask;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\MarkdownConverter;

class CommonResponseRenderer implements ResponseRendererInterface
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
        return $tenantType === 'common';
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
        $data = json_decode($rawOutput, true);
        if (!is_array($data)) {
            return null;
        }

        // ADK orchestrator returns {"output": "...", "attachment": ...} directly
        $output = $data['output'] ?? null;
        if (is_string($output)) {
            return $output;
        }

        return null;
    }
}
