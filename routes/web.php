<?php

declare(strict_types=1);

use App\Http\Controllers\CitiesReportController;
use App\Http\Controllers\CustomerReportController;
use App\Http\Controllers\DeliveriesReportController;
use App\Http\Controllers\IdentifierController;
use App\Http\Controllers\InvoiceBrandingSettingsController;
use App\Http\Controllers\InvoicesReportController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\SalesByItemAverageReportController;
use App\Http\Controllers\SchemaExplorerController;
use App\Http\Controllers\VisitsReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('reports')->group(function (): void {
    Route::get('/sales', [SalesReportController::class, 'index'])->name('reports.sales.index');
    Route::get('/sales/export/pdf', [SalesReportController::class, 'exportPdf'])->name('reports.sales.export.pdf');
    Route::get('/sales/export/csv', [SalesReportController::class, 'exportCsv'])->name('reports.sales.export.csv');
    Route::get('/sales-item-average', [SalesByItemAverageReportController::class, 'index'])->name('reports.sales-item-average.index');
    Route::get('/sales-item-average/items', [SalesByItemAverageReportController::class, 'categoryItems'])->name('reports.sales-item-average.items');
    Route::get('/sales-item-average/export/pdf', [SalesByItemAverageReportController::class, 'exportPdf'])->name('reports.sales-item-average.export.pdf');
    Route::get('/sales-item-average/export/csv', [SalesByItemAverageReportController::class, 'exportCsv'])->name('reports.sales-item-average.export.csv');
    Route::get('/deliveries', [DeliveriesReportController::class, 'index'])->name('reports.deliveries.index');
    Route::post('/deliveries/setup/driver', [DeliveriesReportController::class, 'saveDriver'])->name('reports.deliveries.setup.driver');
    Route::post('/deliveries/setup/companion', [DeliveriesReportController::class, 'saveCompanion'])->name('reports.deliveries.setup.companion');
    Route::post('/deliveries/setup/daily-team', [DeliveriesReportController::class, 'saveDailyTeam'])->name('reports.deliveries.setup.daily-team');
    Route::post('/deliveries/assign-team', [DeliveriesReportController::class, 'assignInvoiceTeam'])->name('reports.deliveries.assign-team');
    Route::post('/deliveries/batch-assign', [DeliveriesReportController::class, 'batchAssignFromPdf'])->name('reports.deliveries.batch-assign');
    Route::get('/deliveries/export/pdf', [DeliveriesReportController::class, 'exportPdf'])->name('reports.deliveries.export.pdf');
    Route::get('/deliveries/export/csv', [DeliveriesReportController::class, 'exportCsv'])->name('reports.deliveries.export.csv');
    Route::get('/invoices', [InvoicesReportController::class, 'index'])->name('reports.invoices.index');
    Route::get('/invoices/items', [InvoicesReportController::class, 'items'])->name('reports.invoices.items');
    Route::get('/invoices/print', [InvoicesReportController::class, 'printInvoice'])->name('reports.invoices.print');
    Route::get('/invoices/export/pdf', [InvoicesReportController::class, 'exportInvoicePdf'])->name('reports.invoices.export.pdf');
    Route::get('/invoice-branding', [InvoiceBrandingSettingsController::class, 'index'])->name('reports.invoice-branding.index');
    Route::post('/invoice-branding', [InvoiceBrandingSettingsController::class, 'save'])->name('reports.invoice-branding.save');
    Route::get('/cities', [CitiesReportController::class, 'index'])->name('reports.cities.index');
    Route::post('/cities/governorates/save', [CitiesReportController::class, 'saveGovernorate'])->name('reports.cities.governorates.save');
    Route::get('/cities/export/pdf', [CitiesReportController::class, 'exportPdf'])->name('reports.cities.export.pdf');
    Route::get('/cities/export/chart-pdf', [CitiesReportController::class, 'exportChartPdf'])->name('reports.cities.export.chart-pdf');
    Route::get('/cities/export/csv', [CitiesReportController::class, 'exportCsv'])->name('reports.cities.export.csv');
    Route::get('/visits', [VisitsReportController::class, 'index'])->name('reports.visits.index');
    Route::get('/visits/export/pdf', [VisitsReportController::class, 'exportPdf'])->name('reports.visits.export.pdf');
    Route::get('/visits/export/csv', [VisitsReportController::class, 'exportCsv'])->name('reports.visits.export.csv');
    Route::get('/customers', [CustomerReportController::class, 'index'])->name('reports.customers.index');
    Route::get('/customers/data', [CustomerReportController::class, 'data'])->name('reports.customers.data');
    Route::get('/schema', [SchemaExplorerController::class, 'index'])->name('reports.schema.index');
    Route::get('/identifier', [IdentifierController::class, 'index'])->name('reports.identifier.index');
});
