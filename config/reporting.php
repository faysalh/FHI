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
    | City column on dbo.tbl_stores (Storage report)
    |--------------------------------------------------------------------------
    */
    'store_city_column' => env('REPORTING_STORE_CITY_COLUMN', ''),

    'store_city_column_candidates' => [
        'fld_city',
        'fld_store_city',
        'fld_address_city',
        'fld_city_name',
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

    /*
    |--------------------------------------------------------------------------
    | Store items → price history row (optional explicit column)
    |--------------------------------------------------------------------------
    |
    | If empty, the app auto-detects a column on tbl_store_items that stores the
    | FK to dbo.tbl_store_item_unit_price_history (e.g. fld_store_item_unit_price_history_id).
    | When found, that history row is preferred over “latest by date” alone.
    | Set REPORTING_STORE_ITEMS_PRICE_HISTORY_POINTER_COLUMN when auto-detection fails.
    |
    */
    'store_items_price_history_pointer_column' => env('REPORTING_STORE_ITEMS_PRICE_HISTORY_POINTER_COLUMN', ''),

    /*
    |--------------------------------------------------------------------------
    | Price history: cap rows at report “as of” date
    |--------------------------------------------------------------------------
    |
    | When false (default), the latest dbo.tbl_store_item_unit_price_history row
    | per item uses the newest price date in the database (last time prices were
    | set), not bounded by the report’s as_of_date / “today”.
    | Set true for point-in-time pricing: only history rows on or before as_of_date.
    |
    */
    'storage_items_price_history_cap_at_as_of_date' => env('REPORTING_STORAGE_ITEMS_PRICE_HISTORY_CAP_AT_AS_OF_DATE', false),

    /*
    |--------------------------------------------------------------------------
    | Storage items: sale price columns (tier 1–5)
    |--------------------------------------------------------------------------
    |
    | When true (default), each displayed price merges item master fld_sale_priceN
    | with the latest history row (see prefer_history_sale_prices and
    | master_sale_price_zero_as_unset). Set false to use history only.
    |
    */
    'storage_items_prefer_master_sale_prices' => env('REPORTING_STORAGE_ITEMS_PREFER_MASTER_SALE_PRICES', true),

    /*
    |--------------------------------------------------------------------------
    | Storage items: history vs master for sale prices 1–5
    |--------------------------------------------------------------------------
    |
    | When prefer_master_sale_prices is true, default is history then master
    | (prefer_history_sale_prices true): the latest price-history snapshot wins
    | when it differs from cached tbl_store_items rows. Set
    | REPORTING_STORAGE_ITEMS_PREFER_HISTORY_SALE_PRICES=false for legacy
    | master-then-history merge.
    |
    | master_sale_price_zero_as_unset (default true): treat master value 0 as
    | “unset” so COALESCE falls through to history (avoids tier 5 showing 0 when
    | history has the real price).
    |
    */
    'storage_items_prefer_history_sale_prices' => env('REPORTING_STORAGE_ITEMS_PREFER_HISTORY_SALE_PRICES', true),

    'storage_items_master_sale_price_zero_as_unset' => env('REPORTING_STORAGE_ITEMS_MASTER_SALE_PRICE_ZERO_AS_UNSET', true),

    /*
    |--------------------------------------------------------------------------
    | PDA stored procedure tier prices (read-only EXEC)
    |--------------------------------------------------------------------------
    |
    | When REPORTING_PDA_PRICING_USER_UUID is set to a valid account user GUID,
    | the app runs EXEC dbo.SP_PDA_Get_Item_All_Units @USERID = ? (read-only)
    | and overlays Sale price 1–5 on Storage items, damages carton price, and
    | damages item search (fld_p1…fld_p5 and alternates fld_sale_price1…5, etc.)
    | — so you can compare with the PDA.
    | No writes to the main database. If empty, SQL-built prices are unchanged.
    |
    | REPORTING_PDA_PRICING_PICK_UNIT: max_scale | min_scale — when an item has
    | multiple rows in tbl_store_item_all_units, which unit row’s prices to use.
    |
    */
    'pda_pricing_user_uuid' => env('REPORTING_PDA_PRICING_USER_UUID', ''),

    'pda_pricing_sp' => env('REPORTING_PDA_PRICING_SP', 'dbo.SP_PDA_Get_Item_All_Units'),

    'pda_pricing_pick_unit' => env('REPORTING_PDA_PRICING_PICK_UNIT', 'max_scale'),

    /*
    |--------------------------------------------------------------------------
    | Client sale price tier (matches Storage items "Price 1" … "Price 5")
    |--------------------------------------------------------------------------
    |
    | Column name matched on dbo.tbl_accounting_accounts and/or
    | dbo.tbl_accounting_account_details (first configured/candidate hit on either).
    | Common: fld_price_group on account_details, fld_sale_price_no on accounts.
    | The report COALESCE(account value, MAX(value) over detail rows per account).
    | When numeric 0–4 (after rounding), labels are وكيل, وكيل 2, ماركيت, جملة, كي
    | (same order as item sale prices 1–5; DB tier is one less). Override
    | with REPORTING_ACCOUNT_CLIENT_PRICE_GROUP_COLUMN when auto-detection
    | picks the wrong column.
    |
    */
    'account_client_price_group_column' => env('REPORTING_ACCOUNT_CLIENT_PRICE_GROUP_COLUMN', ''),

    'account_client_price_group_column_candidates' => [
        'fld_price_group',
        'fld_sale_price_no',
        'fld_customer_sale_price_no',
        'fld_price_list_no',
        'fld_sale_price_group',
        'fld_default_sale_price_no',
        'fld_price_no',
        'fld_customer_price_no',
    ],

    /*
    |--------------------------------------------------------------------------
    | Damages: client’s last sale unit price vs catalog tiers
    |--------------------------------------------------------------------------
    |
    | When true (default), damages carton price is the newest outbound document
    | line unit price for the same client and item on or before the damage date,
    | after line discount (same notion as invoiced amounts). If there is no such
    | sale, falls back to tier + merged history/master pricing (same as before).
    | Set REPORTING_DAMAGES_PRICE_PREFER_LAST_CLIENT_SALE=false to always use the
    | catalog-tier path only.
    |
    */
    'damages_price_prefer_last_client_sale' => env('REPORTING_DAMAGES_PRICE_PREFER_LAST_CLIENT_SALE', true),

    /*
    |--------------------------------------------------------------------------
    | App users (local SQLite — not the reporting SQL Server)
    |--------------------------------------------------------------------------
    |
    | When no users exist yet, the first super-admin account is created from
    | these values on first login attempt or users page load.
    |
    */
    'bootstrap_admin' => [
        'username' => env('REPORTS_BOOTSTRAP_ADMIN_USERNAME', 'admin'),
        'password' => env('REPORTS_BOOTSTRAP_ADMIN_PASSWORD', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard (Erbil governorate by default)
    |--------------------------------------------------------------------------
    |
    | The dashboard filters invoices and sales to cities in a saved governorate.
    | Set REPORTING_DASHBOARD_GOVERNORATE_ID to pin a specific preset, or leave
    | 0 to match REPORTING_DASHBOARD_GOVERNORATE_NAME (default Erbil).
    |
    */
    'dashboard' => [
        'governorate_name' => env('REPORTING_DASHBOARD_GOVERNORATE_NAME', 'Erbil'),
        'saved_governorate_id' => (int) env('REPORTING_DASHBOARD_GOVERNORATE_ID', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Non-working holidays (Eid, etc.) — dashboard business-day calculations
    |--------------------------------------------------------------------------
    |
    | Manage dates in the app: Settings & tools → Holidays (stored in local SQLite).
    | These defaults are imported once when the holidays table is empty.
    | Optional extra dates: REPORTING_NON_WORKING_HOLIDAYS (comma-separated Y-m-d).
    |
    */
    'non_working_holidays' => [
        '2026' => [
            '2026-03-20',
            '2026-03-21',
            '2026-03-22',
            '2026-03-23',
            '2026-05-27',
            '2026-05-28',
            '2026-05-29',
            '2026-05-30',
        ],
    ],

    'non_working_holidays_extra' => env('REPORTING_NON_WORKING_HOLIDAYS', ''),

    /*
    |--------------------------------------------------------------------------
    | Local SQLite backups (app settings — not SQL Server)
    |--------------------------------------------------------------------------
    |
    | Back up and restore the local SQLite files used for users, deliveries,
    | damages, and tasks. Files are stored under sqlite_backup_directory.
    |
    */
    'sqlite_backup_directory' => env('REPORTING_SQLITE_BACKUP_DIRECTORY', ''),

    'sqlite_databases' => [
        'reports_users' => [
            'label' => 'Users & permissions',
            'connection' => 'reports_users_sqlite',
        ],
        'deliveries' => [
            'label' => 'Deliveries, governorates & holidays',
            'connection' => 'deliveries_sqlite',
        ],
        'damages' => [
            'label' => 'Damages entries',
            'connection' => 'damages_sqlite',
        ],
        'operations_tasks' => [
            'label' => 'Operations tasks',
            'connection' => 'operations_tasks_sqlite',
        ],
    ],

];
