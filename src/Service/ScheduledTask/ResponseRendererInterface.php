<?php

namespace App\Service\ScheduledTask;

interface ResponseRendererInterface
{
    public function supports(string $tenantType): bool;

    /**
     * Render raw webhook output as HTML.
     */
    public function render(string $rawOutput): string;
}
