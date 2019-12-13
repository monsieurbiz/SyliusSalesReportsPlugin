<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Controller\Admin;

use MonsieurBiz\SyliusSalesReportsPlugin\Exception\InvalidDateException;
use MonsieurBiz\SyliusSalesReportsPlugin\Form\Type\PeriodType;
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
        $formPeriod = $this->createForm(PeriodType::class);

        // Form not submitted yet
        if (null === $request->request->get($form->getName()) && null === $request->request->get($formPeriod->getName())) {
            return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                'form' => $form->createView(),
                'form_period' => $formPeriod->createView(),
            ]);
        }

        // Submit request data and return form if form is not valid
        if ($request->request->get($form->getName())) {
            $form->submit($request->request->get($form->getName()));
            if (!$form->isSubmitted() || !$form->isValid()) {
                return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                    'form' => $form->createView(),
                    'form_period' => $formPeriod->createView(),
                ]);
            }
        }

        // Submit request data and return form period if form period is not valid
        if ($request->request->get($formPeriod->getName())) {
            $formPeriod->submit($request->request->get($formPeriod->getName()));
            if (!$formPeriod->isSubmitted() || !$formPeriod->isValid()) {
                return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                    'form' => $form->createView(),
                    'form_period' => $formPeriod->createView(),
                ]);
            }
        }

        // Assert retrieved data
        $data = $form->getData();
        $isPeriod = false;
        if (!$data) {
            $data = $formPeriod->getData();
            $isPeriod = true;
        }
        $channel = $data['channel'];
        $from = $data['date'] ?? $data['from'];
        $to = $data['date'] ?? $data['to'];
        Assert::isInstanceOf($channel, ChannelInterface::class);
        Assert::isInstanceOf($from, \DateTimeInterface::class);
        Assert::isInstanceOf($to, \DateTimeInterface::class);

        // Form is valid, we can generate the report
        try {
            $totalSalesResult = $this->reportRepository->getSalesForChannelForDates($channel, $from, $to);
            $averageSalesResult = $this->reportRepository->getAverageSalesForChannelForDates($channel, $from, $to);
        } catch (InvalidDateException $e) {
            $form->addError(new FormError($e->getMessage()));
            return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/index.html.twig', [
                'form' => $form->createView(),
            ]);
        }

        return $this->templatingEngine->renderResponse('@MonsieurBizSyliusSalesReportsPlugin/Admin/view.html.twig', [
            'form' => $form->createView(),
            'form_period' => $formPeriod->createView(),
            'from' => $from,
            'to' => $to,
            'channel' => $data['channel'],
            'total_sales_result' => $totalSalesResult,
            'average_sales_result' => $averageSalesResult,
            'is_period' => $isPeriod
        ]);
    }
}
