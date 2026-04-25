<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | City column on dbo.tbl_accounting_accounts
    |--------------------------------------------------------------------------
    |
    | If set, this name is tried first (must exist on the table). If empty,
    | the visits report tries account_city_column_candidates in order, then
    | any column whose name contains "city". If nothing is found, city shows
    | as blank and city filters are disabled (report still loads).
    |
    */
    'account_city_column' => env('REPORTING_ACCOUNT_CITY_COLUMN', 'fld_city'),

    /*
    |--------------------------------------------------------------------------
    | Fallback city column names (SQL Server INFORMATION_SCHEMA match)
    |--------------------------------------------------------------------------
    */
    'account_city_column_candidates' => [
        'fld_city',
        'fld_account_city',
        'fld_address_city',
        'fld_city_name',
        'fld_account_address_city',
    ],

    /*
    |--------------------------------------------------------------------------
    | Store items (chicken category / description)
    |--------------------------------------------------------------------------
    |
    | Sales lines (tbl_store_document_detail.fld_item_id_ref) join to this table
    | for category breakdowns using the description column (e.g. صدر مسحب، أجنحة).
    |
    */
    'store_items_table' => env('REPORTING_STORE_ITEMS_TABLE', 'dbo.tbl_store_items'),
    'store_items_pk_column' => env('REPORTING_STORE_ITEMS_PK_COLUMN', 'fld_item_id'),
    'store_items_description_column' => env('REPORTING_STORE_ITEMS_DESCRIPTION_COLUMN', 'fld_description'),
    'store_items_name_column' => env('REPORTING_STORE_ITEMS_NAME_COLUMN', 'fld_item_name'),

];
