<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Repositories\StorageItemsReportRepository;
use App\Services\ReportAssemblyPriorityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportAssemblyController extends Controller
{
    public function __construct(
        private readonly StorageItemsReportRepository $storageItemsRepository,
        private readonly ReportAssemblyPriorityService $priorityService
    ) {}

    public function index(Request $request): View
    {
        $settings = $this->priorityService->getSettings();
        $categories = $this->storageItemsRepository->getCategoryOptions(now()->toDateString());
        if ($categories === []) {
            // Assembly setup should stay usable even when "today" has no price-history rows.
            $categories = $this->storageItemsRepository->getCategoryOptions('2099-12-31');
        }
        $categories = array_values(array_unique(array_merge(
            $categories,
            array_map('strval', (array) ($settings['category_priority'] ?? []))
        )));
        $selectedCategory = trim((string) $request->query('category', ''));
        if ($selectedCategory === '' && $categories !== []) {
            $selectedCategory = (string) $categories[0];
        }

        $items = $selectedCategory !== ''
            ? $this->storageItemsRepository->getAssemblyItemsByCategory($selectedCategory)
            : [];

        return view('reports.report-assembly.index', [
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'items' => $items,
            'settings' => $settings,
        ]);
    }

    public function saveCategories(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category_priority' => ['required', 'array'],
            'category_priority.*' => ['required', 'string', 'max:500'],
        ]);

        $this->priorityService->saveCategoryPriority((array) $validated['category_priority']);

        return redirect()
            ->route('reports.report-assembly.index')
            ->with('status', 'Category priority saved.');
    }

    public function saveItems(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:500'],
            'item_priority' => ['required', 'array'],
            'item_priority.*' => ['required', 'string', 'max:500'],
        ]);

        $category = trim((string) $validated['category']);
        $this->priorityService->saveItemPriority($category, (array) $validated['item_priority']);

        return redirect()
            ->route('reports.report-assembly.index', ['category' => $category])
            ->with('status', 'Item priority saved.');
    }
}
