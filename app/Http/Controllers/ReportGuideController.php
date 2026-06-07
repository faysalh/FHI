<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ReportGuide;
use Illuminate\Contracts\View\View;

class ReportGuideController extends Controller
{
    public function index(): View
    {
        return view('reports.guide.index', [
            'sections' => ReportGuide::sectionsForSession(),
        ]);
    }
}
