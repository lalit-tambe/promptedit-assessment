<?php

namespace App\Http\Controllers;

use App\Services\AdminReportService;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    public function __invoke(AdminReportService $reportService): Response
    {
        return Inertia::render('Admin/Report', $reportService->buildReport());
    }
}
