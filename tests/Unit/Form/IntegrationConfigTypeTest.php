<?php

namespace App\Tests\Unit\Form;

use App\Form\IntegrationConfigType;
use App\Integration\CredentialField;
use App\Integration\IntegrationInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

#[AllowMockObjectsWithoutExpectations]
class IntegrationConfigTypeTest extends TypeTestCase
{
    private IntegrationInterface $mockIntegration;

    protected function getExtensions(): array
    {
        $validator = Validation::createValidator();

        return [
            new ValidatorExtension($validator),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock integration
        $this->mockIntegration = $this->createStub(IntegrationInterface::class);
        $this->mockIntegration->method('getCredentialFields')->willReturn([
            new CredentialField('url', 'url', 'URL', 'https://example.com', true, 'Test URL'),
            new CredentialField('username', 'email', 'Email', 'email@example.com', true, 'Test email'),
            new CredentialField('api_token', 'password', 'API Token', '', true, 'Test token'),
        ]);
    }

    public function testFormSubmitValidData(): void
    {
        $formData = [
            'name' => 'Test Integration',
            'url' => 'https://test.atlassian.net',
            'username' => 'test@example.com',
            'api_token' => 'test-token-123',
        ];

        $form = $this->factory->create(IntegrationConfigType::class, null, [
            'integration' => $this->mockIntegration,
            'existing_credentials' => [],
            'is_edit' => false,
        ]);

        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $this->assertTrue($form->isValid());
        $this->assertEquals($formData, $form->getData());
    }

    public function testFormRequiresName(): void
    {
        $formData = [
            'name' => '',
            'url' => 'https://test.atlassian.net',
            'username' => 'test@example.com',
            'api_token' => 'test-token-123',
        ];

        $form = $this->factory->create(IntegrationConfigType::class, null, [
            'integration' => $this->mockIntegration,
            'existing_credentials' => [],
            'is_edit' => false,
        ]);

        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $this->assertFalse($form->isValid());
        $this->assertCount(1, $form->get('name')->getErrors());
    }

    public function testFormAcceptsValidEmail(): void
    {
        $formData = [
            'name' => 'Test Integration',
            'url' => 'https://test.atlassian.net',
            'username' => 'valid@example.com',
            'api_token' => 'test-token-123',
        ];

        $form = $this->factory->create(IntegrationConfigType::class, null, [
            'integration' => $this->mockIntegration,
            'existing_credentials' => [],
            'is_edit' => false,
        ]);

        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $this->assertTrue($form->isValid());
    }

    public function testFormAcceptsValidUrl(): void
    {
        $formData = [
            'name' => 'Test Integration',
            'url' => 'https://test.atlassian.net',
            'username' => 'test@example.com',
            'api_token' => 'test-token-123',
        ];

        $form = $this->factory->create(IntegrationConfigType::class, null, [
            'integration' => $this->mockIntegration,
            'existing_credentials' => [],
            'is_edit' => false,
        ]);

        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $this->assertTrue($form->isValid());
    }

    public function testFormRequiresAllFieldsForNewIntegration(): void
    {
        $formData = [
            'name' => 'Test Integration',
            'url' => 'https://test.atlassian.net',
            'username' => 'test@example.com',
            'api_token' => '', // Empty token
        ];

        $form = $this->factory->create(IntegrationConfigType::class, null, [
            'integration' => $this->mockIntegration,
            'existing_credentials' => [],
            'is_edit' => false,
        ]);

        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $this->assertFalse($form->isValid());
        $this->assertCount(1, $form->get('api_token')->getErrors());
    }

    public function testFormAllowsEmptySensitiveFieldsInEditMode(): void
    {
        $formData = [
            'name' => 'Test Integration',
            'url' => 'https://test.atlassian.net',
            'username' => 'test@example.com',
            'api_token' => '', // Empty token - should be allowed in edit mode
        ];

        $existingCredentials = [
            'name' => 'Test Integration',
            'url' => 'https://test.atlassian.net',
            'username' => 'test@example.com',
            'api_token' => '',
            'api_token_exists' => true, // Indicates existing value
        ];

        $form = $this->factory->create(IntegrationConfigType::class, null, [
            'integration' => $this->mockIntegration,
            'existing_credentials' => $existingCredentials,
            'is_edit' => true,
        ]);

        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $this->assertTrue($form->isValid());
    }

    public function testFormHasMultipleErrors(): void
    {
        $formData = [
            'name' => '',
            'url' => '',
            'username' => '',
            'api_token' => '',
        ];

        $form = $this->factory->create(IntegrationConfigType::class, null, [
            'integration' => $this->mockIntegration,
            'existing_credentials' => [],
            'is_edit' => false,
        ]);

        $form->submit($formData);

        $this->assertTrue($form->isSynchronized());
        $this->assertFalse($form->isValid());

        // Should have errors on multiple required fields
        $this->assertGreaterThan(0, $form->get('name')->getErrors()->count());
        $this->assertGreaterThan(0, $form->get('url')->getErrors()->count());
        $this->assertGreaterThan(0, $form->get('username')->getErrors()->count());
        $this->assertGreaterThan(0, $form->get('api_token')->getErrors()->count());
    }
}
