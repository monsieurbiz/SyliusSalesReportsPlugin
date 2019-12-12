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
    public function indexAction(Request $request): Response
    {
        $form = $this->createForm(DateType::class);

        return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * View the report for a single date
     *
     * @param Request $request
     * @return Response
     */
    public function viewAction(Request $request): Response
    {
        $form = $this->createForm(DateType::class);
        $form->submit($request->request->get($form->getName()));
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createNotFoundException('Form is not valid');
        }

        $data = $form->getData();
        return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/view.html.twig', [
            'form' => $form->createView(),
            'date' => $data['date']
        ]);
    }
}
