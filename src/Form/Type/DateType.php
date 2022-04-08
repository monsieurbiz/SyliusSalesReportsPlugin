<?php

/*
 * This file is part of Monsieur Biz' Sales Reports plugin for Sylius.
 *
 * (c) Monsieur Biz <sylius@monsieurbiz.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Form\Type;

use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType as SymfonyDateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class DateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', SymfonyDateType::class, [
                'widget' => 'single_text',
                'label' => 'monsieurbiz.sales_reports.form.date.label',
                'required' => true,
                'constraints' => [
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
