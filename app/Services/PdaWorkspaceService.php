<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\PdaVoucherRequest;
use App\Support\SalesItemPriceTiers;
use App\Support\SqlServerInClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PdaWorkspaceService
{
    public function __construct(
        private readonly ReportsUsersSqliteService $users,
        private readonly PdaLocationSqliteService $locations,
    ) {}

    /**
     * @return list<array{user_id: string, account_id: string, name: string, code: string}>
     */
    public function listSalesmen(): array
    {
        try {
            if (DB::connection('sqlsrv')->getDriverName() !== 'sqlsrv') {
                return [];
            }

            return Cache::remember('pda.salesmen', 600, function (): array {
                $rows = DB::connection('sqlsrv')->select(
                    'SELECT CAST(fld_user_id_ref AS nvarchar(36)) AS user_id,
                            CAST(fld_account_id AS nvarchar(36)) AS account_id,
                            LTRIM(RTRIM(CAST(fld_account_name AS nvarchar(200)))) AS salesman_name,
                            LTRIM(RTRIM(CAST(fld_account_code AS nvarchar(80)))) AS salesman_code
                     FROM dbo.tbl_accounting_accounts
                     WHERE ISNULL(fld_is_sales_man, 0) = 1
                       AND fld_user_id_ref IS NOT NULL
                     ORDER BY fld_account_name'
                );

                $out = [];
                foreach ($rows as $row) {
                    $userId = $this->guid($row->user_id ?? null);
                    $accountId = $this->guid($row->account_id ?? null);
                    if ($userId === '' || $accountId === '') {
                        continue;
                    }
                    $out[] = [
                        'user_id' => $userId,
                        'account_id' => $accountId,
                        'name' => trim((string) ($row->salesman_name ?? '')),
                        'code' => trim((string) ($row->salesman_code ?? '')),
                    ];
                }

                return $out;
            });
        } catch (Throwable $e) {
            Log::warning('pda.salesmen_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array{user_id: string, account_id: string, name: string, code: string}|null
     */
    public function findSalesman(string $userId): ?array
    {
        $userId = $this->guid($userId);
        foreach ($this->listSalesmen() as $salesman) {
            if ($salesman['user_id'] === $userId) {
                return $salesman;
            }
        }

        return null;
    }

    /**
     * @return array{report_user_id: int, user_id: string, account_id: string, name: string}|null
     */
    public function linkedSalesman(int $reportUserId): ?array
    {
        $user = $this->users->findUserById($reportUserId);
        if ($user === null) {
            return null;
        }

        $userId = $this->guid($user->pda_salesman_user_id ?? null);
        $accountId = $this->guid($user->pda_salesman_account_id ?? null);
        if ($userId === '' || $accountId === '') {
            return null;
        }

        return [
            'report_user_id' => $reportUserId,
            'user_id' => $userId,
            'account_id' => $accountId,
            'name' => trim((string) ($user->pda_salesman_name ?? '')),
        ];
    }

    public function companyName(string $salesmanUserId): string
    {
        try {
            $row = $this->readOne(
                'EXEC dbo.SP_PDA_Get_Company_Info @UserId = ?',
                [$this->guid($salesmanUserId)]
            );
            if ($row === null) {
                return '';
            }

            return trim((string) ($this->value($row, 'companyname') ?? ''));
        } catch (Throwable $e) {
            Log::warning('pda.company_failed', ['message' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function customers(string $salesmanUserId, string $search = ''): array
    {
        $rows = $this->customerCatalog($salesmanUserId);
        $search = mb_strtolower(trim($search));
        if ($search === '') {
            return array_slice($rows, 0, 80);
        }

        $matched = [];
        foreach ($rows as $row) {
            $haystack = mb_strtolower($row['name'].' '.$row['code'].' '.$row['phone'].' '.$row['address']);
            if (str_contains($haystack, $search)) {
                $matched[] = $row;
            }
            if (count($matched) >= 80) {
                break;
            }
        }

        return $matched;
    }

    public function customerCount(string $salesmanUserId): int
    {
        return count($this->customerCatalog($salesmanUserId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function customer(string $salesmanUserId, string $customerId): ?array
    {
        $customerId = $this->guid($customerId);
        foreach ($this->customerCatalog($salesmanUserId) as $row) {
            if ($row['id'] === $customerId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<object>
     */
    public function invoices(string $customerId, bool $showPaid = false): array
    {
        return $this->read(
            'EXEC dbo.SP_PDA_Get_Client_Invoices @AccountID = ?, @TillDate = ?, @ShowPaid = ?',
            [$this->guid($customerId), now()->toDateString(), $showPaid ? 1 : 0]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(string $salesmanUserId, string $search = '', int $limit = 80): array
    {
        $catalog = $this->itemCatalog($salesmanUserId);
        $search = mb_strtolower(trim($search));
        $out = [];
        foreach ($catalog as $item) {
            if ($search !== '') {
                $haystack = mb_strtolower($item['name'].' '.$item['code']);
                if (! str_contains($haystack, $search)) {
                    continue;
                }
            }
            $out[] = $item;
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function item(string $salesmanUserId, string $itemId): ?array
    {
        $itemId = $this->guid($itemId);

        return $this->itemCatalog($salesmanUserId)[$itemId] ?? null;
    }

    /**
     * @return list<object>
     */
    public function itemBalances(string $itemId): array
    {
        return $this->read(
            'EXEC dbo.SP_PDA_Get_Item_BalanceAllStores @ItemUnitID = ?',
            [$this->guid($itemId)]
        );
    }

    /**
     * @return list<object>
     */
    public function targets(string $salesmanUserId): array
    {
        return Cache::remember('pda.targets.'.$this->guid($salesmanUserId), 300, function () use ($salesmanUserId): array {
            return $this->read(
                'EXEC dbo.SP_PDA_Get_Targets @UserID = ?, @GroupByItemGroup = ?',
                [$this->guid($salesmanUserId), 0]
            );
        });
    }

    /**
     * @return list<object>
     */
    public function lastLocations(): array
    {
        return $this->read('EXEC dbo.SP_GET_PDA_LAST_LOCATIONS');
    }

    /**
     * @return list<object>
     */
    public function storedLocations(?string $salesmanUserId, bool $all): array
    {
        return $this->locations->recent($all ? null : $salesmanUserId, $all ? 200 : 50);
    }

    /**
     * @return array{forms: list<array<string, mixed>>, questions: list<array<string, mixed>>, sources: list<array<string, mixed>>}
     */
    public function questionnaireCatalog(string $salesmanUserId): array
    {
        $userId = $this->guid($salesmanUserId);

        return Cache::remember('pda.questions.'.$userId, 300, function () use ($userId): array {
            $forms = [];
            foreach ($this->read('EXEC dbo.SP_PDA_get_questionnaires @UserID = ?', [$userId]) as $row) {
                $forms[] = [
                    'id' => $this->guid($this->value($row, 'fld_id')),
                    'title' => trim((string) ($this->value($row, 'fld_title_ar') ?: $this->value($row, 'fld_title'))),
                    'mandatory' => (int) ($this->value($row, 'fld_is_mandatory') ?? 0) === 1,
                ];
            }

            $questions = [];
            foreach ($this->read('EXEC dbo.SP_PDA_get_questionnaires_questions @UserID = ?', [$userId]) as $row) {
                $questions[] = [
                    'id' => $this->guid($this->value($row, 'fld_sub_id')),
                    'form_id' => $this->guid($this->value($row, 'fld_main_id_ref')),
                    'title' => trim((string) ($this->value($row, 'fld_title_ar') ?: $this->value($row, 'fld_title'))),
                    'type' => trim((string) ($this->value($row, 'fld_field_type') ?? '')),
                    'source_id' => $this->guid($this->value($row, 'fld_data_source_id_ref')),
                    'required' => (int) ($this->value($row, 'fld_is_required') ?? 0) === 1,
                ];
            }

            $sources = [];
            foreach ($this->read('EXEC dbo.SP_PDA_get_questionnaires_data_sources @UserID = ?', [$userId]) as $row) {
                $sources[] = [
                    'id' => $this->guid($this->value($row, 'fld_sub_id')),
                    'source_id' => $this->guid($this->value($row, 'fld_main_id_ref')),
                    'title' => trim((string) ($this->value($row, 'fld_title_ar') ?: $this->value($row, 'fld_title'))),
                ];
            }

            return ['forms' => $forms, 'questions' => $questions, 'sources' => $sources];
        });
    }

    /**
     * @return list<object>
     */
    public function questionnaireResults(string $questionnaireId): array
    {
        return $this->read(
            'EXEC dbo.SP_PDA_Get_Questionnaire_result @QuestionnaireId = ?',
            [$this->guid($questionnaireId)]
        );
    }

    /**
     * @return list<object>
     */
    public function visitReasons(): array
    {
        return Cache::remember('pda.visit_reasons', 600, function (): array {
            return $this->read('EXEC dbo.SP_PDA_Get_Salesman_No_Invoice_Visit_Reasons');
        });
    }

    /**
     * @return list<object>
     */
    public function unposted(string $salesmanUserId): array
    {
        return $this->read(
            'SELECT CAST(fld_voucher_id AS nvarchar(36)) AS voucher_id,
                    fld_no, fld_voucher_Type, fld_date, fld_total, fld_paid, fld_customer_name, fld_notes, fld_lat, fld_long
             FROM dbo.tbl_pda_store_title
             WHERE fld_user_id = ? AND ISNULL(fld_is_posted, 0) = 0 AND ISNULL(fld_is_canceled, 0) = 0
             ORDER BY fld_date DESC',
            [$this->guid($salesmanUserId)]
        );
    }

    /**
     * @return array{visit_id: string, start_date: string}|null
     */
    public function openVisit(string $salesmanUserId, string $customerId): ?array
    {
        $row = $this->readOne(
            'SELECT TOP 1 CAST(fld_visit_id AS nvarchar(36)) AS visit_id, fld_start_date
             FROM dbo.tbl_pda_visits
             WHERE fld_user_id_ref = ? AND fld_customer_id_ref = ? AND fld_end_date IS NULL
             ORDER BY fld_start_date DESC',
            [$this->guid($salesmanUserId), $this->guid($customerId)]
        );
        if ($row === null) {
            return null;
        }

        return [
            'visit_id' => $this->guid($row->visit_id ?? null),
            'start_date' => (string) ($row->fld_start_date ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array{visit_id: string}
     */
    public function startVisit(array $salesman, array $customer): array
    {
        $existing = $this->openVisit($salesman['user_id'], $customer['id']);
        if ($existing !== null) {
            return $existing;
        }

        $visitId = $this->newGuid();
        $now = now()->toDateTimeString();
        $this->write()->insert(
            'INSERT INTO dbo.tbl_pda_visits (
                fld_visit_id, fld_start_date, fld_user_id_ref, fld_customer_id_ref,
                fld_visit_is_failed, sync_id, sync_created, sync_last_updated
            ) VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
            [$visitId, $now, $salesman['user_id'], $customer['id'], $this->newGuid(), $now, $now]
        );

        return ['visit_id' => $visitId, 'start_date' => $now];
    }

    /**
     * @param  array{user_id: string, account_id: string, name: string}  $salesman
     */
    public function endVisit(array $salesman, string $visitId, bool $failed, ?int $reasonId, string $note): void
    {
        $visitId = $this->guid($visitId);
        $this->assertOwnVisit($salesman['user_id'], $visitId);
        if ($failed && ($reasonId === null || $reasonId <= 0)) {
            throw new RuntimeException('Choose a reason for a visit with no invoice.');
        }

        $now = now()->toDateTimeString();
        $updated = $this->write()->update(
            'UPDATE dbo.tbl_pda_visits
             SET fld_end_date = ?, fld_note = ?, fld_visit_is_failed = ?, fld_no_visit_reason_id_ref = ?, sync_last_updated = ?
             WHERE fld_visit_id = ? AND fld_user_id_ref = ? AND fld_end_date IS NULL',
            [$now, $note !== '' ? $note : null, $failed ? 1 : 0, $failed ? $reasonId : null, $now, $visitId, $salesman['user_id']]
        );
        if ($updated < 1) {
            throw new RuntimeException('This visit is already finished.');
        }
    }

    /**
     * @param  array{user_id: string, account_id: string, name: string}  $salesman
     * @param  array<string, mixed>  $customer
     * @param  list<array{item_id: string, unit_row_id: string, qty: float, price: float, discount: float}>  $lines
     */
    public function saveVoucher(
        array $salesman,
        array $customer,
        string $visitId,
        string $kind,
        float $paid,
        float $headerDiscount,
        string $notes,
        float $latitude,
        float $longitude,
        ?float $accuracy,
        array $lines,
        bool $allowZeroStock = false,
    ): string {
        $this->assertCoordinates($latitude, $longitude);
        $visitId = $this->guid($visitId);
        $this->assertOwnVisit($salesman['user_id'], $visitId);
        $type = match ($kind) {
            'order' => 'SO',
            'return' => 'SR',
            'sale', 'receipt' => 'S',
            default => throw new RuntimeException('Choose a sale, order, return, or receipt.'),
        };
        if ($kind === 'receipt' && $paid <= 0) {
            throw new RuntimeException('Enter the amount collected.');
        }
        if ($kind !== 'receipt' && $lines === []) {
            throw new RuntimeException('Add at least one item.');
        }

        $catalog = $this->itemCatalog($salesman['user_id']);
        $accountPriceGroup = array_key_exists('price_group_account', $customer)
            ? (int) $customer['price_group_account']
            : max(0, ((int) ($customer['price_group'] ?? 1)) - 1);
        $priceGroup = SalesItemPriceTiers::columnForAccountGroup($accountPriceGroup);
        $normalized = [];
        $total = 0.0;
        foreach ($lines as $line) {
            $item = $catalog[$this->guid($line['item_id'])] ?? null;
            if ($item === null) {
                throw new RuntimeException('One of the items is not on this salesman’s list.');
            }
            if ($kind === 'sale' && ! $allowZeroStock && (float) $item['balance'] <= 0) {
                throw new RuntimeException($item['name'].' has no stock.');
            }
            $unit = null;
            foreach ($item['units'] as $candidate) {
                if ($candidate['unit_row_id'] === $this->guid($line['unit_row_id'])) {
                    $unit = $candidate;
                    break;
                }
            }
            if ($unit === null) {
                throw new RuntimeException('Choose a unit for '.$item['name'].'.');
            }
            $qty = (float) $line['qty'];
            $price = (float) ($unit['prices'][$priceGroup] ?? 0);
            if ($qty <= 0 || $price < 0) {
                throw new RuntimeException('Check the quantity.');
            }
            $total += $qty * $price;
            $normalized[] = [
                'item_id' => $item['id'],
                'unit_row_id' => $unit['unit_row_id'],
                'unit_id' => $unit['unit_id'],
                'scale' => $unit['scale'],
                'qty' => $qty,
                'price' => $price,
                'discount' => 0.0,
            ];
        }
        $headerDiscount = 0.0;
        if ($paid < 0) {
            throw new RuntimeException('The collected amount cannot be negative.');
        }

        $voucherId = $this->newGuid();
        $logId = $this->newGuid();
        $now = now()->toDateTimeString();
        $number = $this->nextVoucherNumber($salesman['user_id'], $type);
        $note = PdaVoucherRequest::noteOrDefault($notes);

        $write = $this->write();
        try {
            $write->transaction(function () use ($write, $voucherId, $logId, $now, $salesman, $customer, $visitId, $type, $paid, $headerDiscount, $note, $latitude, $longitude, $number, $total, $normalized, $accountPriceGroup): void {
                $write->insert(
                    'INSERT INTO dbo.tbl_pda_coordination_logs (
                        fld_log_id, fld_lat, fld_long, fld_type, fld_visit_id, fld_date, fld_server_date,
                        fld_user_id_ref, sync_id, sync_created, sync_last_updated
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$logId, $latitude, $longitude, 'web', $visitId, $now, $now, $salesman['user_id'], $this->newGuid(), $now, $now]
                );
                $write->insert(
                    'INSERT INTO dbo.tbl_pda_store_title (
                        fld_voucher_id, fld_customer_id, fld_date, fld_discount, fld_paid, fld_total, fld_voucher_Type,
                        fld_user_id, fld_is_posted, Sync_id, sync_last_updated, fld_visit_id, fld_rout_id, fld_is_canceled,
                        fld_customer_name, fld_customer_code, fld_customer_phone, fld_customer_address, fld_lat, fld_long,
                        fld_notes, fld_price_group, sync_created, fld_no
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $voucherId,
                        $customer['id'],
                        $now,
                        $headerDiscount,
                        $paid,
                        round($total, 4),
                        $type,
                        $salesman['user_id'],
                        $this->newGuid(),
                        $now,
                        $visitId,
                        $customer['route_id'] !== '' ? $customer['route_id'] : null,
                        $customer['name'],
                        $customer['code'],
                        $customer['phone'],
                        $customer['address'],
                        $latitude,
                        $longitude,
                        $note !== '' ? $note : null,
                        $accountPriceGroup,
                        $now,
                        $number,
                    ]
                );
                $rowNumber = 1;
                foreach ($normalized as $line) {
                    $write->insert(
                        'INSERT INTO dbo.tbl_pda_store_details (
                            fld_voucher_detail_id, fld_voucher_id_ref, fld_item_id_ref, fld_unite_price, fld_quantity,
                            Sync_id, sync_last_updated, fld_discount, fld_item_store_unit_id_ref, fld_unit_id_ref,
                            fld_unit_scale, fld_row_number, sync_created, fld_is_promotion, fld_free_quantity
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)',
                        [
                            $this->newGuid(),
                            $voucherId,
                            $line['item_id'],
                            $line['price'],
                            $line['qty'],
                            $this->newGuid(),
                            $now,
                            $line['discount'],
                            $line['unit_row_id'],
                            $line['unit_id'] !== '' ? $line['unit_id'] : null,
                            $line['scale'],
                            $rowNumber,
                            $now,
                        ]
                    );
                    $rowNumber++;
                }
            });
        } catch (Throwable $e) {
            Log::error('pda.voucher_failed', ['message' => $e->getMessage()]);
            throw new RuntimeException('Could not save the voucher.', 0, $e);
        }

        try {
            $this->locations->record(
                $voucherId,
                $salesman['user_id'],
                $salesman['account_id'],
                $salesman['name'],
                $customer['id'],
                $customer['name'],
                $kind,
                $latitude,
                $longitude,
                $accuracy,
            );
        } catch (Throwable $e) {
            Log::error('pda.location_copy_failed', ['voucher_id' => $voucherId, 'message' => $e->getMessage()]);
            $this->deleteUnpostedVoucher($voucherId, $logId);
            throw new RuntimeException('The location could not be stored, so the voucher was not kept.');
        }

        return $voucherId;
    }

    /**
     * @param  array{user_id: string, account_id: string, name: string}  $salesman
     * @param  array<string, string>  $answers  question id => value
     */
    public function saveQuestionnaire(array $salesman, string $customerId, string $questionnaireId, array $answers): void
    {
        $normalizedAnswers = [];
        foreach ($answers as $questionId => $value) {
            $questionId = $this->guid($questionId);
            if ($questionId !== '') {
                $normalizedAnswers[$questionId] = trim((string) $value);
            }
        }
        $answers = $normalizedAnswers;

        $catalog = $this->questionnaireCatalog($salesman['user_id']);
        $form = null;
        foreach ($catalog['forms'] as $candidate) {
            if ($candidate['id'] === $this->guid($questionnaireId)) {
                $form = $candidate;
                break;
            }
        }
        if ($form === null) {
            throw new RuntimeException('That questionnaire is not available.');
        }
        if ($this->customer($salesman['user_id'], $customerId) === null) {
            throw new RuntimeException('Choose a customer on your list.');
        }

        $questions = array_values(array_filter(
            $catalog['questions'],
            static fn (array $question): bool => $question['form_id'] === $form['id']
        ));
        foreach ($questions as $question) {
            $value = trim((string) ($answers[$question['id']] ?? ''));
            if ($question['required'] && $value === '') {
                throw new RuntimeException('Answer the required question: '.$question['title']);
            }
        }

        $answerId = $this->newGuid();
        $now = now()->toDateTimeString();
        $write = $this->write();
        $write->transaction(function () use ($write, $answerId, $now, $salesman, $customerId, $form, $questions, $answers): void {
            $write->insert(
                'INSERT INTO dbo.tbl_pda_questionnaires_answers (
                    fld_id, fld_client_id_ref, fld_salesman_id_ref, fld_date, sync_id, sync_last_updated, sync_created, fld_questionnaire_id_ref
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$answerId, $this->guid($customerId), $salesman['account_id'], $now, $this->newGuid(), $now, $now, $form['id']]
            );
            foreach ($questions as $question) {
                $value = trim((string) ($answers[$question['id']] ?? ''));
                if ($value === '') {
                    continue;
                }
                $write->insert(
                    'INSERT INTO dbo.tbl_pda_questionnaires_answers_sub (
                        fld_sub_id, fld_main_id_ref, fld_question_id_ref, fld_value
                    ) VALUES (?, ?, ?, ?)',
                    [$this->newGuid(), $answerId, $question['id'], $value]
                );
            }
        });
    }

    public function assertCoordinates(float $latitude, float $longitude): void
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new RuntimeException('Location permission is required before this can be saved.');
        }
        if ($latitude === 0.0 && $longitude === 0.0) {
            throw new RuntimeException('Location permission is required before this can be saved.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customerCatalog(string $salesmanUserId): array
    {
        $userId = $this->guid($salesmanUserId);

        return Cache::remember('pda.customers.v3.'.$userId, 180, function () use ($userId): array {
            $this->extendTimeLimit();
            $rows = $this->read(
                'EXEC dbo.SP_PDA_Get_Customers @UserID = ?, @DontReturnData = ?',
                [$userId, 0]
            );
            $out = [];
            foreach ($rows as $row) {
                $id = $this->guid($this->value($row, 'fld_customer_id'));
                if ($id === '') {
                    continue;
                }
                $accountPriceGroup = (int) ($this->value($row, 'fld_price_group') ?? 0);
                $out[] = [
                    'id' => $id,
                    'code' => trim((string) ($this->value($row, 'fld_code') ?? '')),
                    'name' => trim((string) ($this->value($row, 'fld_name') ?? '')),
                    'phone' => trim((string) ($this->value($row, 'fld_phone') ?? '')),
                    'address' => trim((string) ($this->value($row, 'fld_address') ?? '')),
                    'balance' => (float) ($this->value($row, 'fld_balance') ?? 0),
                    'discount' => (float) ($this->value($row, 'fld_discount') ?? 0),
                    'price_group' => SalesItemPriceTiers::columnForAccountGroup($accountPriceGroup),
                    'price_group_account' => $accountPriceGroup,
                    'zone' => trim((string) ($this->value($row, 'fld_zone_name') ?? '')),
                    'route' => trim((string) ($this->value($row, 'fld_route_name') ?? '')),
                    'route_id' => $this->guid($this->value($row, 'fld_route_id_ref')),
                    'last_amount' => (float) ($this->value($row, 'lastvisitamount') ?? 0),
                    'last_date' => (string) ($this->value($row, 'lastvisitdate') ?? ''),
                    'lat' => $this->value($row, 'fld_lat'),
                    'lng' => $this->value($row, 'fld_long'),
                ];
            }

            return $out;
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemCatalog(string $salesmanUserId): array
    {
        $userId = $this->guid($salesmanUserId);

        return Cache::remember('pda.items.v3.'.$userId, 600, function () use ($userId): array {
            $this->extendTimeLimit();
            $withImages = [];
            foreach ($this->read(
                'SELECT CAST(fld_item_id AS nvarchar(36)) AS id
                 FROM dbo.tbl_store_items
                 WHERE fld_item_image IS NOT NULL AND DATALENGTH(fld_item_image) > 32'
            ) as $row) {
                $imageId = $this->guid($row->id ?? null);
                if ($imageId !== '') {
                    $withImages[$imageId] = true;
                }
            }

            $balances = [];
            foreach ($this->read('EXEC dbo.SP_PDA_Get_Items @USERID = ?, @GetImages = ?', [$userId, 0]) as $row) {
                $itemId = $this->guid($this->value($row, 'fld_item_id') ?? $this->value($row, 'fld_item_id_ref'));
                if ($itemId === '') {
                    continue;
                }
                $balances[$itemId] = (float) ($this->value($row, 'fld_current_balance') ?? 0);
            }

            $items = [];
            foreach ($this->read('EXEC dbo.SP_PDA_Get_Item_All_Units @USERID = ?', [$userId]) as $row) {
                $itemId = $this->guid($this->value($row, 'fld_item_id_ref'));
                $unitRowId = $this->guid($this->value($row, 'fld_item_store_unit_id'));
                if ($itemId === '' || $unitRowId === '') {
                    continue;
                }
                if (! isset($items[$itemId])) {
                    $items[$itemId] = [
                        'id' => $itemId,
                        'name' => trim((string) ($this->value($row, 'fld_name') ?? '')),
                        'code' => trim((string) ($this->value($row, 'fld_code') ?? '')),
                        'balance' => $balances[$itemId] ?? (float) ($this->value($row, 'fld_current_balance') ?? 0),
                        'has_image' => isset($withImages[$itemId]),
                        'units' => [],
                    ];
                }
                $prices = [];
                for ($tier = 1; $tier <= 5; $tier++) {
                    $prices[$tier] = (float) ($this->value($row, 'fld_p'.$tier) ?? 0);
                }
                $items[$itemId]['units'][] = [
                    'unit_row_id' => $unitRowId,
                    'unit_id' => $this->guid($this->value($row, 'fld_unit_id_ref')),
                    'name' => trim((string) ($this->value($row, 'fld_unite') ?? '')),
                    'scale' => (float) ($this->value($row, 'fld_unit_scale') ?? 1),
                    'discount' => (float) ($this->value($row, 'fld_sale_discount') ?? 0),
                    'prices' => $prices,
                ];
            }

            $items = $this->attachItemGroups($items);
            uasort($items, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            return $items;
        });
    }

    /**
     * Category is the parent item group. Subcategory is the group on the item.
     * Groups with no parent are categories that have no subcategory.
     *
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    private function attachItemGroups(array $items): array
    {
        foreach ($items as &$item) {
            $item['category_id'] = '';
            $item['category_name'] = '';
            $item['category_sort'] = 0;
            $item['subcategory_id'] = '';
            $item['subcategory_name'] = '';
            $item['subcategory_sort'] = 0;
        }
        unset($item);

        if ($items === []) {
            return $items;
        }

        foreach (SqlServerInClause::conditionChunks('i.fld_item_id', array_keys($items)) as [$groupSql, $bindings]) {
            $rows = $this->read(
                "SELECT CAST(i.fld_item_id AS nvarchar(36)) AS item_id,
                        CAST(g.fld_item_group_id AS nvarchar(36)) AS group_id,
                        LTRIM(RTRIM(CAST(g.fld_item_group_name AS nvarchar(200)))) AS group_name,
                        ISNULL(g.fld_sort, 0) AS group_sort,
                        CAST(p.fld_item_group_id AS nvarchar(36)) AS parent_id,
                        LTRIM(RTRIM(CAST(p.fld_item_group_name AS nvarchar(200)))) AS parent_name,
                        ISNULL(p.fld_sort, 0) AS parent_sort
                 FROM dbo.tbl_store_items i
                 INNER JOIN dbo.tbl_store_item_groups g ON g.fld_item_group_id = i.fld_item_group_id_ref
                 LEFT JOIN dbo.tbl_store_item_groups p ON p.fld_item_group_id = g.fld_item_group_parent_id_ref
                 WHERE {$groupSql}",
                $bindings
            );
            foreach ($rows as $row) {
                $itemId = $this->guid($row->item_id ?? null);
                $groupId = $this->guid($row->group_id ?? null);
                $groupName = trim((string) ($row->group_name ?? ''));
                if ($itemId === '' || $groupId === '' || $groupName === '' || ! isset($items[$itemId])) {
                    continue;
                }
                $parentId = $this->guid($row->parent_id ?? null);
                $parentName = trim((string) ($row->parent_name ?? ''));
                if ($parentId !== '' && $parentName !== '') {
                    $items[$itemId]['category_id'] = $parentId;
                    $items[$itemId]['category_name'] = $parentName;
                    $items[$itemId]['category_sort'] = (int) $row->parent_sort;
                    $items[$itemId]['subcategory_id'] = $groupId;
                    $items[$itemId]['subcategory_name'] = $groupName;
                    $items[$itemId]['subcategory_sort'] = (int) $row->group_sort;
                } else {
                    $items[$itemId]['category_id'] = $groupId;
                    $items[$itemId]['category_name'] = $groupName;
                    $items[$itemId]['category_sort'] = (int) $row->group_sort;
                }
            }
        }

        return $items;
    }

    public function itemThumbnail(string $itemId): ?string
    {
        $itemId = $this->guid($itemId);
        if ($itemId === '') {
            return null;
        }

        $dir = storage_path('app/pda-item-thumbs');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        $dest = $dir.DIRECTORY_SEPARATOR.$itemId.'.jpg';
        if (is_file($dest) && filesize($dest) > 0) {
            return $dest;
        }

        $lock = fopen($dir.DIRECTORY_SEPARATOR.'thumb.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            return null;
        }

        try {
            return $this->writeItemThumbnail($itemId, $dir, $dest);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeItemThumbnail(string $itemId, string $dir, string $dest): ?string
    {
        if (is_file($dest) && filesize($dest) > 0) {
            return $dest;
        }

        $row = DB::connection('sqlsrv')->selectOne(
            'SELECT CONVERT(varchar(max), fld_item_image, 2) AS hex
             FROM dbo.tbl_store_items
             WHERE fld_item_id = ? AND fld_item_image IS NOT NULL AND DATALENGTH(fld_item_image) > 32',
            [$itemId]
        );
        $hex = is_string($row->hex ?? null) ? $row->hex : '';
        $bytes = $hex !== '' ? hex2bin($hex) : false;
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        $source = $dir.DIRECTORY_SEPARATOR.$itemId.'.src';
        file_put_contents($source, $bytes);
        $program = $this->thumbnailProgram($dir);

        try {
            if ($program === null) {
                Log::warning('pda.item_thumb_failed', ['item' => $itemId, 'message' => 'Thumbnail program is unavailable.']);

                return null;
            }

            $result = Process::timeout(20)->run([$program, $source, $dest]);
            if (! $result->successful() || ! is_file($dest) || filesize($dest) === 0) {
                Log::warning('pda.item_thumb_failed', [
                    'item' => $itemId,
                    'error' => trim($result->errorOutput().' '.$result->output()),
                ]);
                if (is_file($dest)) {
                    unlink($dest);
                }

                return null;
            }
        } catch (Throwable $e) {
            Log::warning('pda.item_thumb_failed', ['item' => $itemId, 'message' => $e->getMessage()]);

            return null;
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
        }

        return $dest;
    }

    private function thumbnailProgram(string $dir): ?string
    {
        $exe = $dir.DIRECTORY_SEPARATOR.'thumb-v1.exe';
        if (is_file($exe)) {
            return $exe;
        }

        $csc = getenv('WINDIR').'\\Microsoft.NET\\Framework64\\v4.0.30319\\csc.exe';
        if (! is_file($csc)) {
            $csc = getenv('WINDIR').'\\Microsoft.NET\\Framework\\v4.0.30319\\csc.exe';
        }
        if (! is_file($csc)) {
            return null;
        }

        $source = $dir.DIRECTORY_SEPARATOR.'thumb-v1.cs';
        file_put_contents($source, <<<'CS'
using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.IO;

sealed class Thumb {
    static int Main(string[] args) {
        if (args.Length < 2) return 2;
        try {
            using (Image img = Image.FromFile(args[0])) {
                double scale = Math.Min(280.0 / img.Width, 280.0 / img.Height);
                if (scale > 1) scale = 1;
                int width = Math.Max(1, (int) Math.Round(img.Width * scale));
                int height = Math.Max(1, (int) Math.Round(img.Height * scale));
                using (Bitmap bmp = new Bitmap(width, height))
                using (Graphics g = Graphics.FromImage(bmp)) {
                    g.InterpolationMode = InterpolationMode.HighQualityBicubic;
                    g.Clear(Color.White);
                    g.DrawImage(img, 0, 0, width, height);
                    ImageCodecInfo codec = null;
                    foreach (ImageCodecInfo candidate in ImageCodecInfo.GetImageEncoders()) {
                        if (candidate.MimeType == "image/jpeg") codec = candidate;
                    }
                    if (codec == null) return 3;
                    EncoderParameters parameters = new EncoderParameters(1);
                    parameters.Param[0] = new EncoderParameter(Encoder.Quality, 75L);
                    string temp = args[1] + ".part";
                    bmp.Save(temp, codec, parameters);
                    if (File.Exists(args[1])) File.Delete(args[1]);
                    File.Move(temp, args[1]);
                }
            }
            return 0;
        } catch (Exception ex) {
            Console.Error.WriteLine(ex.Message);
            return 1;
        }
    }
}
CS);

        $result = Process::timeout(30)->run([$csc, '/nologo', '/optimize+', '/out:'.$exe, '/r:System.Drawing.dll', $source]);
        if (! $result->successful() || ! is_file($exe)) {
            Log::warning('pda.item_thumb_compiler_failed', ['error' => trim($result->errorOutput().' '.$result->output())]);

            return null;
        }

        return $exe;
    }

    private function nextVoucherNumber(string $salesmanUserId, string $type): int
    {
        $fromProcedure = 0;
        try {
            foreach ($this->read('EXEC dbo.SP_PDA_get_last_no @UserId = ?', [$salesmanUserId]) as $row) {
                $rowType = strtoupper(trim((string) ($this->value($row, 'fld_voucher_type') ?? '')));
                if ($rowType === strtoupper($type)) {
                    $fromProcedure = (int) ($this->value($row, 'fld_last_no') ?? 0);
                }
            }
        } catch (Throwable $e) {
            Log::warning('pda.last_no_failed', ['message' => $e->getMessage()]);
        }

        $row = $this->readOne(
            'SELECT ISNULL(MAX(fld_no), 0) AS last_no
             FROM dbo.tbl_pda_store_title
             WHERE fld_user_id = ? AND fld_voucher_Type = ?',
            [$salesmanUserId, $type]
        );

        return max($fromProcedure, (int) ($row->last_no ?? 0)) + 1;
    }

    private function assertOwnVisit(string $salesmanUserId, string $visitId): void
    {
        $row = $this->readOne(
            'SELECT TOP 1 fld_end_date
             FROM dbo.tbl_pda_visits
             WHERE fld_visit_id = ? AND fld_user_id_ref = ?',
            [$visitId, $salesmanUserId]
        );
        if ($row === null) {
            throw new RuntimeException('This visit was not found for your salesman.');
        }
        if ($row->fld_end_date !== null && (string) $row->fld_end_date !== '') {
            throw new RuntimeException('This visit is already finished.');
        }
    }

    private function deleteUnpostedVoucher(string $voucherId, string $logId): void
    {
        try {
            $write = $this->write();
            $write->delete('DELETE FROM dbo.tbl_pda_store_details WHERE fld_voucher_id_ref = ?', [$voucherId]);
            $write->delete(
                'DELETE FROM dbo.tbl_pda_store_title WHERE fld_voucher_id = ? AND ISNULL(fld_is_posted, 0) = 0',
                [$voucherId]
            );
            $write->delete('DELETE FROM dbo.tbl_pda_coordination_logs WHERE fld_log_id = ?', [$logId]);
        } catch (Throwable $e) {
            Log::error('pda.voucher_rollback_failed', ['voucher_id' => $voucherId, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @return list<object>
     */
    private function read(string $sql, array $bindings = []): array
    {
        $rows = DB::connection('sqlsrv')->select($this->withNoCount($sql), $bindings);

        return is_array($rows) ? $rows : [];
    }

    private function readOne(string $sql, array $bindings = []): ?object
    {
        $row = DB::connection('sqlsrv')->selectOne($this->withNoCount($sql), $bindings);

        return $row instanceof \stdClass ? $row : null;
    }

    private function withNoCount(string $sql): string
    {
        if (stripos(ltrim($sql), 'EXEC ') === 0) {
            return 'SET NOCOUNT ON; '.$sql;
        }

        return $sql;
    }

    private function write(): \Illuminate\Database\Connection
    {
        $name = (string) config('reporting.database_sync_connection', 'sqlsrv_write');
        $connection = DB::connection($name);
        if ($connection->getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('PDA saving is only available when SQL Server is configured.');
        }

        return $connection;
    }

    private function value(object $row, string $name): mixed
    {
        $needle = strtolower($name);
        foreach (get_object_vars($row) as $key => $value) {
            if (strtolower((string) $key) === $needle) {
                return $value;
            }
        }

        return null;
    }

    private function guid(mixed $value): string
    {
        $text = strtoupper(trim((string) $value, "{} \t\n\r"));
        if ($text === '' || ! Str::isUuid($text)) {
            return '';
        }

        return $text;
    }

    private function newGuid(): string
    {
        return strtoupper((string) Str::uuid());
    }

    private function extendTimeLimit(): void
    {
        $limit = ini_get('max_execution_time');
        if ($limit !== false && (int) $limit > 0 && (int) $limit < 180) {
            set_time_limit(180);
        }
    }
}
