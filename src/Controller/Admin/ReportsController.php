<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use MonsieurBiz\SyliusSalesReportsPlugin\Form\Type\DateType;
use Symfony\Component\Templating\EngineInterface;

final class ReportsController extends AbstractController
{
    /** @var EngineInterface */
    private $templatingEngine;

    /**
     * ReportsController constructor.
     * @param EngineInterface $templatingEngine
     */
    public function __construct(
        EngineInterface $templatingEngine
    ) {
        $this->templatingEngine = $templatingEngine;
    }
    /**
     * Generate the form to search from date
     *
     * @param Request $request
     * @return Response
     */
    public function formAction(Request $request): Response
    {
        $form = $this->createForm(DateType::class);

        return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
