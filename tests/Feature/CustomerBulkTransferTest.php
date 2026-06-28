<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CustomerBulkTransferTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    public function test_owner_can_export_only_current_account_customers(): void
    {
        [$owner, $account] = $this->accountWithOwner();
        $otherAccount = Account::factory()->create();
        Customer::factory()->for($account)->create([
            'name' => 'Beta Client',
            'phone' => '+380501111111',
            'email' => 'beta@example.com',
        ]);
        Customer::factory()->for($account)->create([
            'name' => 'Alpha Client',
            'phone' => '+380502222222',
            'email' => 'alpha@example.com',
        ]);
        Customer::factory()->for($otherAccount)->create([
            'name' => 'Other Client',
            'phone' => '+380503333333',
            'email' => 'other@example.com',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.customers.export', $account));

        $response->assertOk()
            ->assertDownload('customers-export-'.now()->format('Y-m-d').'.xlsx');

        $this->assertSame([
            ['name', 'phone', 'email'],
            ['Alpha Client', '+380502222222', 'alpha@example.com'],
            ['Beta Client', '+380501111111', 'beta@example.com'],
        ], $this->spreadsheetRowsFromResponse($response));
    }

    public function test_owner_can_download_import_example_xlsx(): void
    {
        [$owner, $account] = $this->accountWithOwner();

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.customers.example', $account));

        $response->assertOk()
            ->assertDownload('customers-import-example.xlsx');

        $rows = $this->spreadsheetRowsFromResponse($response);

        $this->assertSame(['name', 'phone', 'email'], $rows[0]);
        $this->assertSame('+38(063)123-12-12', $rows[1][1]);
    }

    public function test_xlsx_import_creates_new_customers_and_reports_weird_rows(): void
    {
        [$owner, $account] = $this->accountWithOwner();
        Customer::factory()->for($account)->create([
            'name' => 'Stored Phone',
            'phone' => '+38(063)123-12-12',
            'email' => 'stored-phone@example.com',
        ]);
        Customer::factory()->for($account)->create([
            'name' => 'Stored Email',
            'phone' => '+380991111111',
            'email' => 'existing@example.com',
        ]);

        $file = $this->uploadedSpreadsheet([
            ['name', 'phone', 'email'],
            ['New Masked', '+38 (050) 123-45-67', 'NEW@example.com'],
            ['Existing Phone', '380631231212', 'phone-match@example.com'],
            ['Existing Email', '+380661111111', 'EXISTING@example.com'],
            ['Letters Phone', 'abc-def', 'letters@example.com'],
            ['Bad Email', '+380671111111', 'bad-email'],
            ['Email Only', '', 'email.only@example.com'],
            ['No Contact', '', ''],
        ]);

        $response = $this->postImport($owner, $account, $file);

        $response->assertOk()
            ->assertJsonPath('summary.total_rows', 7)
            ->assertJsonPath('summary.inserted', 2)
            ->assertJsonPath('summary.already_found', 2)
            ->assertJsonPath('summary.skipped', 3)
            ->assertJsonFragment(['status' => 'already_found', 'reason' => 'phone'])
            ->assertJsonFragment(['status' => 'already_found', 'reason' => 'email'])
            ->assertJsonFragment(['status' => 'skipped', 'reason' => 'phone_not_numeric'])
            ->assertJsonFragment(['status' => 'skipped', 'reason' => 'invalid_email'])
            ->assertJsonFragment(['status' => 'skipped', 'reason' => 'missing_contact']);

        $newCustomer = Customer::whereBelongsTo($account)->where('email', 'new@example.com')->firstOrFail();
        $emailOnlyCustomer = Customer::whereBelongsTo($account)->where('email', 'email.only@example.com')->firstOrFail();

        $this->assertSame('New Masked', $newCustomer->name);
        $this->assertSame('+380501234567', $newCustomer->phone);
        $this->assertSame($account->default_language, $newCustomer->default_language);
        $this->assertSame('Email Only', $emailOnlyCustomer->name);
        $this->assertNull($emailOnlyCustomer->phone);
        $this->assertSame(1, Customer::whereBelongsTo($account)->where('email', 'existing@example.com')->count());

        $activityLog = AccountActivityLog::whereBelongsTo($account)
            ->where('route_name', 'dashboard.accounts.customers.import')
            ->firstOrFail();

        $this->assertSame('POST', $activityLog->method);
        $this->assertSame(200, $activityLog->status_code);
    }

    public function test_csv_import_uses_same_account_only_for_duplicate_matching(): void
    {
        [$owner, $account] = $this->accountWithOwner();
        $otherAccount = Account::factory()->create();
        Customer::factory()->for($otherAccount)->create([
            'name' => 'Other Account Client',
            'phone' => '+380501234567',
            'email' => 'same@example.com',
        ]);

        $file = $this->uploadedCsv([
            ['name', 'phone', 'email'],
            ['Same Contact New Account', '380501234567', 'same@example.com'],
        ], ';');

        $response = $this->postImport($owner, $account, $file);

        $response->assertOk()
            ->assertJsonPath('summary.inserted', 1)
            ->assertJsonPath('summary.already_found', 0)
            ->assertJsonPath('summary.skipped', 0);

        $customer = Customer::whereBelongsTo($account)->where('email', 'same@example.com')->firstOrFail();

        $this->assertSame('+380501234567', $customer->phone);
    }

    public function test_import_rejects_wrong_headers(): void
    {
        [$owner, $account] = $this->accountWithOwner();
        $file = $this->uploadedCsv([
            ['full_name', 'phone', 'email'],
            ['Wrong Header', '+380501234567', 'wrong@example.com'],
        ]);

        $response = $this->postImport($owner, $account, $file);

        $response->assertUnprocessable();
        $this->assertArrayHasKey('file', $response->json('errors'));

        $this->assertSame(0, Customer::whereBelongsTo($account)->where('email', 'wrong@example.com')->count());
    }

    public function test_import_rejects_invalid_file_type(): void
    {
        [$owner, $account] = $this->accountWithOwner();
        $path = $this->temporaryPath('txt');
        file_put_contents($path, "name,phone,email\nBad,+380501234567,bad@example.com\n");

        $response = $this->postImport($owner, $account, new UploadedFile($path, 'customers.txt', 'text/plain', null, true));

        $response->assertUnprocessable();
        $this->assertArrayHasKey('file', $response->json('errors'));
    }

    public function test_import_rejects_unreadable_xlsx_file(): void
    {
        [$owner, $account] = $this->accountWithOwner();
        $path = $this->temporaryPath('xlsx');
        file_put_contents($path, 'not a real spreadsheet');

        $response = $this->postImport($owner, $account, new UploadedFile($path, 'broken.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

        $response->assertUnprocessable();
        $this->assertArrayHasKey('file', $response->json('errors'));
    }

    public function test_unrelated_user_cannot_import_or_export_customer_files(): void
    {
        [, $account] = $this->accountWithOwner();
        $user = User::factory()->create();
        $file = $this->uploadedCsv([
            ['name', 'phone', 'email'],
            ['Blocked', '+380501234567', 'blocked@example.com'],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.accounts.customers.export', $account))
            ->assertForbidden();

        $this->postImport($user, $account, $file)
            ->assertForbidden();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * @return array{0: User, 1: Account}
     */
    private function accountWithOwner(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['country_code' => 'UA', 'default_language' => 'uk']);
        $account->addOwner($owner);

        return [$owner, $account];
    }

    private function postImport(User $user, Account $account, UploadedFile $file): TestResponse
    {
        return $this->actingAs($user)
            ->post(route('dashboard.accounts.customers.import', $account), [
                'file' => $file,
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function uploadedSpreadsheet(array $rows, string $filename = 'customers.xlsx'): UploadedFile
    {
        $path = $this->temporaryPath('xlsx');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1);
                $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
            }
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function uploadedCsv(array $rows, string $delimiter = ','): UploadedFile
    {
        $path = $this->temporaryPath('csv');
        $handle = fopen($path, 'wb');

        foreach ($rows as $row) {
            fputcsv($handle, $row, $delimiter, '"', '\\');
        }

        fclose($handle);

        return new UploadedFile($path, 'customers.csv', 'text/csv', null, true);
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function spreadsheetRowsFromResponse(TestResponse $response): array
    {
        $path = $this->temporaryPath('xlsx');
        file_put_contents($path, $response->streamedContent());

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];

        for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $values = [];

            for ($column = 1; $column <= 3; $column++) {
                $values[] = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row)->getValue();
            }

            $rows[] = $values;
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    private function temporaryPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ladna-customers-');
        $target = $path.'.'.$extension;
        rename($path, $target);
        $this->temporaryFiles[] = $target;

        return $target;
    }
}
