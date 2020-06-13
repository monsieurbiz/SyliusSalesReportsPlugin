<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Form\Type;

use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType as SymfonyDateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class DateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('date', SymfonyDateType::class, [
                'widget' => 'single_text',
                'label' => 'monsieurbiz.sales_reports.form.date.label',
                'required' => true,
                'constraints' => [
                    new Assert\Date([]),
                    new Assert\NotBlank([]),
                ],
            ])
            ->add('channel', ChannelChoiceType::class, [
                'required' => true,
                'label' => 'monsieurbiz.sales_reports.form.channel.label',
                'constraints' => [
                    new Assert\NotBlank([]),
                ],
            ])
        ;
    }
}
