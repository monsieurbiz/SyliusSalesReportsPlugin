<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Form\Type;

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
                'label' => 'monsieur_biz_sales_reports.form.date.label',
                'required' => true,
                'constraints' => [
                    new Assert\Date([])
                ],
            ])
        ;
    }
}
