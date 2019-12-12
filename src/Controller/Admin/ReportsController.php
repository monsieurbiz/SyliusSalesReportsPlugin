<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSalesReportsPlugin\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReportsController extends AbstractController
{
    /**
     * Generate the form to search from date
     *
     * @param Request $request
     * @return Response
     */
    public function formAction(Request $request): Response
    {
        return new Response('Ok');
    }
}
