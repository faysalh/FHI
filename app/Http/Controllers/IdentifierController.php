<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Repositories\IdentifierRepository;
use Illuminate\Contracts\View\View;

class IdentifierController extends Controller
{
    public function __construct(
        private readonly IdentifierRepository $identifierRepository
    ) {
    }

    public function index(): View
    {
        return view('reports.identifier.index', [
            'terms' => $this->buildTerms(),
        ]);
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     sample_columns: list<string>,
     *     sample_rows: list<list<string>>
     * }>
     */
    private function buildTerms(): array
    {
        $salesmanSamples = $this->identifierRepository->fetchSalesmanIdentifierSamples(5);
        if ($salesmanSamples === []) {
            $salesmanSamples = $this->salesmanUnavailableRows();
        }

        $clientSamples = $this->identifierRepository->fetchClientIdentifierSamples(5);
        if ($clientSamples === []) {
            $clientSamples = $this->clientUnavailableRows();
        }

        $citySamples = $this->identifierRepository->fetchCityIdentifierSamples(5);
        if ($citySamples === []) {
            $citySamples = $this->cityUnavailableRows();
        }

        $itemCategorySamples = $this->identifierRepository->fetchItemCategoryDescriptionSamples(5);
        if ($itemCategorySamples === []) {
            $itemCategorySamples = $this->itemCategoryUnavailableRows();
        }

        return [
            [
                'key' => 'salesman',
                'label' => 'Salesman',
                'description' => 'A salesman is identified from dbo.tbl_accounting_accounts. Use fld_account_name as the salesman label when fld_parent_account_id_ref equals '.IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID.'. Other rows are not treated as salesmen under this rule.',
                'sample_columns' => [
                    'Table',
                    'fld_account_name (returned)',
                    'fld_parent_account_id_ref',
                    'fld_account_id',
                    'Note',
                ],
                'sample_rows' => $salesmanSamples,
            ],
            [
                'key' => 'client',
                'label' => 'Client',
                'description' => 'A client account is a row in dbo.tbl_accounting_accounts where fld_sales_man_id_ref equals the fld_account_id of a salesman account. Salesmen are the accounts whose fld_parent_account_id_ref is '.IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID.' (see Salesman). Other accounts are not clients under this definition.',
                'sample_columns' => [
                    'fld_account_code (client)',
                    'fld_account_name (client)',
                    'fld_account_id (client)',
                    'fld_sales_man_id_ref',
                    'Salesman fld_account_name',
                ],
                'sample_rows' => $clientSamples,
            ],
            [
                'key' => 'city',
                'label' => 'City',
                'description' => 'For visits and city filters, city is taken from dbo.tbl_accounting_accounts.fld_city on client rows (accounts that match the Client rule). The visits report uses this column for multi-select cities and for display.',
                'sample_columns' => [
                    'Table',
                    'Column',
                    'Example fld_city',
                    '—',
                    'Note',
                ],
                'sample_rows' => $citySamples,
            ],
            [
                'key' => 'item_category',
                'label' => 'Item category (chicken)',
                'description' => 'On the Sales report: Category breakdown aggregates by description only. Category breakdown based on clients sums per client and per description (one row per client × category). Each line links to dbo.tbl_store_items via tbl_store_document_detail.fld_item_id_ref = tbl_store_items.'.(string) config('reporting.store_items_pk_column', 'fld_item_id').'. The label is '.(string) config('reporting.store_items_description_column', 'fld_description').' (e.g. صدر مسحب or أجنحة).',
                'sample_columns' => [
                    'Table',
                    'Column',
                    'Example description',
                    'Join note',
                    'Note',
                ],
                'sample_rows' => $itemCategorySamples,
            ],
        ];
    }

    /**
     * @return list<list<string>>
     */
    private function salesmanUnavailableRows(): array
    {
        return [[
            'dbo.tbl_accounting_accounts',
            '(Could not load live rows — use SQL Server, or no rows have fld_parent_account_id_ref = '.IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID.')',
            '',
            '',
            '',
        ]];
    }

    /**
     * @return list<list<string>>
     */
    private function clientUnavailableRows(): array
    {
        return [[
            '',
            '(Could not load live rows — check DB, or no accounts link fld_sales_man_id_ref to a salesman fld_account_id.)',
            '',
            '',
            '',
        ]];
    }

    /**
     * @return list<list<string>>
     */
    private function cityUnavailableRows(): array
    {
        return [[
            'dbo.tbl_accounting_accounts',
            'fld_city',
            '(Could not load distinct fld_city — use SQL Server and client rows with fld_city set.)',
            '',
            '',
        ]];
    }

    /**
     * @return list<list<string>>
     */
    private function itemCategoryUnavailableRows(): array
    {
        return [[
            (string) config('reporting.store_items_table', 'dbo.tbl_store_items'),
            (string) config('reporting.store_items_description_column', 'fld_description'),
            '(Could not load descriptions — use SQL Server and rows in tbl_store_items with fld_description set.)',
            '',
            '',
        ]];
    }
}
