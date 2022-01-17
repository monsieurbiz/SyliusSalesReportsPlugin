<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Form\Type;

use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType as SymfonyDateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class PeriodType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('from', SymfonyDateType::class, [
                'widget' => 'single_text',
                'label' => 'monsieurbiz.sales_reports.form.from_date.label',
                'required' => true,
                'constraints' => [
                    new Assert\Type("\DateTimeInterface"),
                    new Assert\NotBlank([]),
                ],
            ])
            ->add('to', SymfonyDateType::class, [
                'widget' => 'single_text',
                'label' => 'monsieurbiz.sales_reports.form.to_date.label',
                'required' => true,
                'constraints' => [
                    new Assert\Type("\DateTimeInterface"),
                    new Assert\NotBlank([]),
                ],
            ])
            ->add('channel', ChannelChoiceType::class, [
                'label' => 'monsieurbiz.sales_reports.form.channel.label',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank([]),
                ],
            ])
        ;
    }
}
