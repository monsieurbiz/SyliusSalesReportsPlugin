<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Controller\Admin;

use MonsieurBiz\SyliusSalesReportsPlugin\Exception\InvalidDateException;
use MonsieurBiz\SyliusSalesReportsPlugin\Repository\ReportRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use MonsieurBiz\SyliusSalesReportsPlugin\Form\Type\DateType;
use Symfony\Component\Templating\EngineInterface;
use Webmozart\Assert\Assert;

final class ReportsController extends AbstractController
{
    /**
     * @var ReportRepository
     */
    protected $reportRepository;

    /**
     * @var EngineInterface
     */
    private $templatingEngine;

    /**
     * ReportsController constructor.
     * @param EngineInterface $templatingEngine
     * @param ReportRepository $reportRepository
     */
    public function __construct(
        EngineInterface $templatingEngine,
        ReportRepository $reportRepository
    ) {
        $this->templatingEngine = $templatingEngine;
        $this->reportRepository = $reportRepository;
    }

    /**
     * View the report for a single date
     *
     * @param Request $request
     * @return Response
     */
    public function indexAction(Request $request): Response
    {
        $form = $this->createForm(DateType::class);

        // Form not submitted yet
        if (null === $request->request->get($form->getName())) {
            return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        // Submit request data and return form if form is not valid
        $form->submit($request->request->get($form->getName()));
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        // Assert retrieved data
        $data = $form->getData();
        $channel = $data['channel'];
        $date = $data['date'];
        Assert::isInstanceOf($channel, ChannelInterface::class);
        Assert::isInstanceOf($date, \DateTimeInterface::class);

        // Form is valid, we can generate the report
        try {
            $totalSalesResult = $this->reportRepository->getSalesForChannelForDates($channel, $date);
        } catch (InvalidDateException $e) {
            $form->addError(new FormError($e->getMessage()));
            return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/view.html.twig', [
            'form' => $form->createView(),
            'date' => $data['date'],
            'channel' => $data['channel'],
            'totalSalesResult' => $totalSalesResult
        ]);
    }
}
