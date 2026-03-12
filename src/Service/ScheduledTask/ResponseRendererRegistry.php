<?php

namespace App\Service\ScheduledTask;

class ResponseRendererRegistry
{
    /** @var iterable<ResponseRendererInterface> */
    private iterable $renderers;

    /**
     * @param iterable<ResponseRendererInterface> $renderers
     */
    public function __construct(iterable $renderers)
    {
        $this->renderers = $renderers;
    }

    public function render(string $tenantType, string $rawOutput): string
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->supports($tenantType)) {
                return $renderer->render($rawOutput);
            }
        }

        // Fallback: escaped raw text
        return '<pre>' . htmlspecialchars($rawOutput) . '</pre>';
    }
}
