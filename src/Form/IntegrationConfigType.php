<?php

namespace App\Form;

use App\Entity\IntegrationConfig;
use App\Integration\IntegrationInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class IntegrationConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var IntegrationInterface $integration */
        $integration = $options['integration'];
        $existingCredentials = $options['existing_credentials'];
        $isEdit = $options['is_edit'];

        // Add name field
        $builder->add('name', TextType::class, [
            'label' => 'integration.instance_name',
            'required' => true,
            'attr' => [
                'placeholder' => 'integration.instance_name_placeholder'
            ],
            'constraints' => [
                new Assert\NotBlank(
                    message: 'validation.name_required'
                )
            ]
        ]);

        // Add dynamic credential fields
        foreach ($integration->getCredentialFields() as $field) {
            // Skip OAuth fields - they're handled separately in the template
            if ($field->getType() === 'oauth') {
                continue;
            }

            $fieldType = match ($field->getType()) {
                'email' => EmailType::class,
                'password' => PasswordType::class,
                'url' => UrlType::class,
                default => TextType::class,
            };

            $constraints = [];
            $isSensitiveField = in_array($field->getName(), ['api_token', 'password', 'client_secret']);

            // For sensitive fields in edit mode, they're not required (keep existing)
            // For new configs or non-sensitive fields, apply required validation
            if ($field->isRequired() && (!$isEdit || !$isSensitiveField)) {
                $constraints[] = new Assert\NotBlank(
                    message: 'validation.field_required'
                );
            }

            // Add email validation for email fields
            if ($field->getType() === 'email') {
                $constraints[] = new Assert\Email(
                    message: 'validation.email_invalid'
                );
            }

            // Add URL validation for URL fields
            if ($field->getType() === 'url') {
                $constraints[] = new Assert\Url(
                    message: 'validation.url_invalid',
                    requireTld: true
                );
            }

            $fieldOptions = [
                'label' => $field->getLabel(),
                'required' => $field->isRequired() && (!$isEdit || !$isSensitiveField),
                'attr' => [
                    'placeholder' => $field->getPlaceholder() ?? ''
                ],
                'constraints' => $constraints,
                'help' => $field->getHint()
            ];

            // For sensitive fields in edit mode, add help text
            if ($isSensitiveField && $isEdit && !empty($existingCredentials[$field->getName() . '_exists'])) {
                $fieldOptions['help'] = 'integration.keep_existing';
                $fieldOptions['required'] = false;
            }

            $builder->add($field->getName(), $fieldType, $fieldOptions);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null, // We don't map directly to entity
            'integration' => null,
            'existing_credentials' => [],
            'is_edit' => false,
        ]);

        $resolver->setRequired(['integration']);
        $resolver->setAllowedTypes('integration', IntegrationInterface::class);
        $resolver->setAllowedTypes('existing_credentials', 'array');
        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
