<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceBrandingSettingsRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class InvoiceBrandingSettingsController extends Controller
{
    private const CACHE_KEY = 'reports.invoice_branding_settings.v1';

    public function index(): View
    {
        return view('reports.invoice-branding.index', [
            'settings' => $this->getSettings(),
        ]);
    }

    public function save(InvoiceBrandingSettingsRequest $request): RedirectResponse
    {
        $input = $request->validated();
        $settings = $this->getSettings();

        $settings['company_name'] = trim((string) ($input['company_name'] ?? ''));
        $settings['company_mobile'] = trim((string) ($input['company_mobile'] ?? ''));
        $settings['company_address'] = trim((string) ($input['company_address'] ?? ''));
        $settings['footer_note'] = trim((string) ($input['footer_note'] ?? ''));
        $invoiceDirection = (string) ($input['invoice_direction'] ?? 'rtl');
        $settings['invoice_direction'] = in_array($invoiceDirection, ['rtl', 'ltr'], true)
            ? $invoiceDirection
            : 'rtl';

        $removeLogo = (bool) ($input['remove_logo'] ?? false);
        if ($removeLogo && $settings['logo_path'] !== '') {
            Storage::disk('public')->delete($settings['logo_path']);
            $settings['logo_path'] = '';
        }

        if ($request->hasFile('logo')) {
            if ($settings['logo_path'] !== '') {
                Storage::disk('public')->delete($settings['logo_path']);
            }
            $path = $request->file('logo')->store('invoice-branding', 'public');
            $settings['logo_path'] = (string) $path;
        }

        try {
            Cache::store('database')->forever(self::CACHE_KEY, $settings);
        } catch (Throwable) {
            Cache::forever(self::CACHE_KEY, $settings);
        }

        return redirect()->route('reports.invoice-branding.index')->with('status', 'Invoice branding settings saved.');
    }

    /**
     * @return array{company_name:string,company_mobile:string,company_address:string,footer_note:string,logo_path:string,invoice_direction:string}
     */
    public static function getSettings(): array
    {
        try {
            /** @var mixed $raw */
            $raw = Cache::store('database')->get(self::CACHE_KEY, []);
        } catch (Throwable) {
            /** @var mixed $raw */
            $raw = Cache::get(self::CACHE_KEY, []);
        }
        $settings = is_array($raw) ? $raw : [];
        $invoiceDirection = (string) ($settings['invoice_direction'] ?? 'rtl');

        return [
            'company_name' => trim((string) ($settings['company_name'] ?? 'N.Z.Y. Company')),
            'company_mobile' => trim((string) ($settings['company_mobile'] ?? '')),
            'company_address' => trim((string) ($settings['company_address'] ?? '')),
            'footer_note' => trim((string) ($settings['footer_note'] ?? 'غير مسؤولين عن سوء التخزين، التلف ومنتهى الصلاحية غير مرجوع.')),
            'logo_path' => trim((string) ($settings['logo_path'] ?? '')),
            'invoice_direction' => in_array($invoiceDirection, ['rtl', 'ltr'], true)
                ? $invoiceDirection
                : 'rtl',
        ];
    }
}

