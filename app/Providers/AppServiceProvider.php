<?php

namespace App\Providers;

use App\Http\Controllers\InvoiceBrandingSettingsController;
use App\Support\ReportAuthSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::directive('displayNumber', function (string $expression): string {
            return "<?php echo e(display_number({$expression})); ?>";
        });

        View::composer('reports.*', static function ($view): void {
            $settings = InvoiceBrandingSettingsController::getSettings();
            $logoPath = trim((string) ($settings['logo_path'] ?? ''));

            $view->with('reportBranding', [
                'company_name' => trim((string) ($settings['company_name'] ?? '')),
                'company_mobile' => trim((string) ($settings['company_mobile'] ?? '')),
                'company_address' => trim((string) ($settings['company_address'] ?? '')),
                // Intentionally omit footer_note for general report usage.
                'logo_url' => $logoPath !== '' ? Storage::disk('public')->url($logoPath) : '',
            ]);

            $view->with(
                'canReceiveTaskNotifications',
                ReportAuthSession::isAuthenticated() && ReportAuthSession::canAccessReport('tasks')
            );
        });
    }
}
