<?php

namespace App\Form;

use App\Entity\Prompt;
use App\Integration\IntegrationRegistry;
use App\Integration\PersonalizedSkillInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class PromptType extends AbstractType
{
    public function __construct(
        private IntegrationRegistry $integrationRegistry,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'prompt.title',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(['max' => 255]),
                ],
                'attr' => [
                    'placeholder' => 'prompt.title_placeholder',
                    'class' => 'form-control',
                ],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'prompt.content',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(),
                ],
                'attr' => [
                    'rows' => 10,
                    'placeholder' => 'prompt.content_placeholder',
                    'class' => 'form-control',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'prompt.description',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'prompt.description_placeholder',
                    'class' => 'form-control',
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'prompt.category',
                'required' => false,
                'choices' => array_flip(Prompt::getCategories()),
                'placeholder' => 'prompt.select_category',
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('skill', ChoiceType::class, [
                'label' => 'prompt.skill',
                'required' => false,
                'choices' => $this->buildSkillChoices(),
                'placeholder' => 'prompt.select_skill',
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('scope', ChoiceType::class, [
                'label' => 'prompt.scope',
                'required' => true,
                'choices' => array_flip(Prompt::getScopes()),
                'expanded' => true,
                'attr' => [
                    'class' => 'scope-radio-group',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Prompt::class,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildSkillChoices(): array
    {
        $choices = [];
        foreach ($this->integrationRegistry->all() as $integration) {
            if ($integration instanceof PersonalizedSkillInterface) {
                $choices[$integration->getName()] = $integration->getType();
            }
        }
        ksort($choices);

        return $choices;
    }
}
