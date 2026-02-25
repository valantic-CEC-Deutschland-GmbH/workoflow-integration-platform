<?php

namespace App\Tests\Unit\Enum;

use App\Enum\PromptPlatform;
use PHPUnit\Framework\TestCase;

class PromptPlatformTest extends TestCase
{
    public function testAllCasesHaveLabels(): void
    {
        foreach (PromptPlatform::cases() as $case) {
            $label = $case->label();
            $this->assertNotEmpty($label, "Case {$case->name} should have a non-empty label");
        }
    }

    public function testChoicesReturnsLabelToValueMap(): void
    {
        $choices = PromptPlatform::choices();

        $this->assertNotEmpty($choices);
        $this->assertCount(count(PromptPlatform::cases()), $choices);

        // Keys should be labels, values should be string enum values
        foreach ($choices as $label => $value) {
            $this->assertIsString($label);
            $this->assertIsString($value);

            // Value should be a valid enum case
            $case = PromptPlatform::tryFrom($value);
            $this->assertNotNull($case, "Value '{$value}' should be a valid PromptPlatform case");
            $this->assertEquals($label, $case->label());
        }
    }

    public function testSpecificPlatformLabels(): void
    {
        $this->assertEquals('ChatGPT', PromptPlatform::CHATGPT->label());
        $this->assertEquals('Claude Code', PromptPlatform::CLAUDE_CODE->label());
        $this->assertEquals('Workoflow', PromptPlatform::WORKOFLOW->label());
        $this->assertEquals('Copilot (Microsoft 365)', PromptPlatform::COPILOT->label());
        $this->assertEquals('GitHub Copilot', PromptPlatform::GITHUB_COPILOT->label());
    }

    public function testTryFromWithValidValues(): void
    {
        $this->assertEquals(PromptPlatform::CHATGPT, PromptPlatform::tryFrom('chatgpt'));
        $this->assertEquals(PromptPlatform::CLAUDE_CODE, PromptPlatform::tryFrom('claude_code'));
        $this->assertEquals(PromptPlatform::WORKOFLOW, PromptPlatform::tryFrom('workoflow'));
    }

    public function testTryFromWithInvalidValue(): void
    {
        $this->assertNull(PromptPlatform::tryFrom('nonexistent'));
        $this->assertNull(PromptPlatform::tryFrom(''));
        $this->assertNull(PromptPlatform::tryFrom('ChatGPT')); // Case-sensitive
    }

    public function testCaseCount(): void
    {
        // Ensure we have the expected 21 platform cases
        $this->assertCount(21, PromptPlatform::cases());
    }
}
