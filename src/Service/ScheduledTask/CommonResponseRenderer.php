<?php

namespace App\Service\ScheduledTask;

use League\CommonMark\CommonMarkConverter;

class CommonResponseRenderer implements ResponseRendererInterface
{
    private CommonMarkConverter $markdownConverter;

    public function __construct()
    {
        $this->markdownConverter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
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
