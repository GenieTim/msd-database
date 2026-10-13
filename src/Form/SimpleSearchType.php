<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<mixed>
 */
class SimpleSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('search', TextType::class, [
                'label' => false,
                'attr' => [
                    'placeholder' => 'Enter chemical name, CAS number, or formula (e.g. Nitric Acid, 7697-37-2, HNO3)...',
                    'aria-label' => 'Search chemical substance by name, CAS number, or formula',
                    'class' => 'form-control form-control-lg',
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Please enter a chemical name, CAS, or formula.'),
                    new Assert\Length(min: 1, max: 255),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET',
            'csrf_protection' => false,
        ]);
    }
}
