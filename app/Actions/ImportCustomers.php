<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Customer;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;

class ImportCustomers
{
    private const COLUMNS = ['name', 'phone', 'email'];

    public function __construct(private readonly PhoneNumberNormalizer $phoneNumberNormalizer) {}

    /**
     * @return array{
     *     summary: array{total_rows: int, inserted: int, already_found: int, skipped: int},
     *     rows: array<int, array<string, mixed>>
     * }
     *
     * @throws ValidationException
     */
    public function execute(Account $account, UploadedFile $file): array
    {
        $rows = $this->readRows($file);
        $header = array_shift($rows);

        if (! $header || ! $this->validHeader($header['values'])) {
            throw ValidationException::withMessages([
                'file' => __('app.customer_import_invalid_header'),
            ]);
        }

        $lookups = $this->customerLookups($account);
        $summary = [
            'total_rows' => 0,
            'inserted' => 0,
            'already_found' => 0,
            'skipped' => 0,
        ];
        $results = [];

        foreach ($rows as $row) {
            if ($this->rowIsBlank($row['values'])) {
                continue;
            }

            $summary['total_rows']++;
            $result = $this->importRow($account, $row['number'], $row['values'], $lookups);
            $summary[$result['status']]++;
            $results[] = $result;
        }

        return [
            'summary' => $summary,
            'rows' => $results,
        ];
    }

    /**
     * @param  array{emails: array<string, array<string, mixed>>, phones: array<string, array<string, mixed>>}  $lookups
     * @return array<string, mixed>
     */
    private function importRow(Account $account, int $rowNumber, array $values, array &$lookups): array
    {
        if ($this->hasExtraData($values)) {
            return $this->skippedRow($rowNumber, $values, 'invalid_columns');
        }

        $values = array_pad(array_slice($values, 0, 3), 3, '');
        $name = $this->cellText($values[0]);
        $phoneInput = $this->cellText($values[1]);
        $email = mb_strtolower($this->cellText($values[2]));
        $countryCode = $account->country_code ?? 'UA';

        if ($name === '') {
            return $this->skippedRow($rowNumber, $values, 'missing_name');
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->skippedRow($rowNumber, $values, 'invalid_email', name: $name, email: $email, phone: $phoneInput);
        }

        $phone = null;
        $phoneDigits = null;

        if ($phoneInput !== '') {
            $phoneDigits = $this->phoneDigits($phoneInput);

            if ($phoneDigits === '') {
                return $this->skippedRow($rowNumber, $values, 'phone_not_numeric', name: $name, email: $email, phone: $phoneInput);
            }

            if (! $this->phoneNumberNormalizer->isValid($phoneInput, $countryCode)) {
                return $this->skippedRow($rowNumber, $values, 'invalid_phone', name: $name, email: $email, phone: $phoneInput);
            }

            $phone = $this->phoneNumberNormalizer->normalize($phoneInput, $countryCode);
            $phoneDigits = $this->phoneDigits($phone);
        }

        if ($phone === null && $email === '') {
            return $this->skippedRow($rowNumber, $values, 'missing_contact', name: $name);
        }

        if ($phoneDigits && isset($lookups['phones'][$phoneDigits])) {
            return $this->alreadyFoundRow($rowNumber, $name, $phone, $email, 'phone', $lookups['phones'][$phoneDigits]);
        }

        if ($email !== '' && isset($lookups['emails'][$email])) {
            return $this->alreadyFoundRow($rowNumber, $name, $phone, $email, 'email', $lookups['emails'][$email]);
        }

        try {
            $customer = $account->customers()->create([
                'name' => $name,
                'phone' => $phone,
                'email' => $email === '' ? null : $email,
                'default_language' => $account->default_language,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintException($exception)) {
                throw $exception;
            }

            return $this->alreadyFoundRow($rowNumber, $name, $phone, $email, 'database', null);
        }

        $this->addCustomerToLookups($lookups, $customer);

        return [
            'row' => $rowNumber,
            'status' => 'inserted',
            'name' => $name,
            'phone' => $phone,
            'email' => $email === '' ? null : $email,
            'message' => __('app.customer_import_row_inserted'),
        ];
    }

    /**
     * @return array<int, array{number: int, values: array<int, string>}>
     */
    private function readRows(UploadedFile $file): array
    {
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());

        return $extension === 'csv'
            ? $this->readCsvRows($file)
            : $this->readSpreadsheetRows($file);
    }

    /**
     * @return array<int, array{number: int, values: array<int, string>}>
     */
    private function readCsvRows(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => __('app.customer_import_unreadable_file'),
            ]);
        }

        $delimiter = $this->detectCsvDelimiter($handle);
        $rows = [];
        $rowNumber = 0;

        while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rowNumber++;
            $rows[] = [
                'number' => $rowNumber,
                'values' => array_map(fn (mixed $value): string => $this->cellText($value), $values),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  resource  $handle
     */
    private function detectCsvDelimiter($handle): string
    {
        $sample = '';

        for ($line = 0; $line < 5 && ! feof($handle); $line++) {
            $sample .= (string) fgets($handle);
        }

        rewind($handle);

        $firstLine = collect(preg_split('/\R/u', $sample) ?: [])
            ->first(fn (string $line): bool => trim($line) !== '') ?? '';

        return collect([',', ';', "\t"])
            ->mapWithKeys(fn (string $delimiter): array => [$delimiter => count(str_getcsv($firstLine, $delimiter, '"', '\\'))])
            ->sortDesc()
            ->keys()
            ->first() ?? ',';
    }

    /**
     * @return array<int, array{number: int, values: array<int, string>}>
     */
    private function readSpreadsheetRows(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => __('app.customer_import_unreadable_file'),
            ]);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = max(1, $sheet->getHighestDataRow());
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $columnCount = max(3, $highestColumn);
        $rows = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $values = [];

            for ($column = 1; $column <= $columnCount; $column++) {
                $values[] = $this->spreadsheetCellText($sheet->getCell(Coordinate::stringFromColumnIndex($column).$row));
            }

            $rows[] = [
                'number' => $row,
                'values' => $values,
            ];
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * @return array{emails: array<string, array<string, mixed>>, phones: array<string, array<string, mixed>>}
     */
    private function customerLookups(Account $account): array
    {
        $lookups = [
            'emails' => [],
            'phones' => [],
        ];

        foreach ($account->customers()
            ->select(['id', 'name', 'email', 'phone'])
            ->orderBy('id')
            ->cursor() as $customer) {
            $this->addCustomerToLookups($lookups, $customer);
        }

        return $lookups;
    }

    /**
     * @param  array{emails: array<string, array<string, mixed>>, phones: array<string, array<string, mixed>>}  $lookups
     */
    private function addCustomerToLookups(array &$lookups, Customer $customer): array
    {
        $payload = [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];

        if (filled($customer->email)) {
            $lookups['emails'][mb_strtolower($customer->email)] = $payload;
        }

        $phoneDigits = $this->phoneDigits($customer->phone);

        if ($phoneDigits !== '') {
            $lookups['phones'][$phoneDigits] = $payload;
        }

        return $lookups;
    }

    private function validHeader(array $values): bool
    {
        $header = array_map(fn (mixed $value): string => mb_strtolower($this->cellText($value)), array_slice($values, 0, 3));

        return $header === self::COLUMNS && ! $this->hasExtraData($values);
    }

    private function rowIsBlank(array $values): bool
    {
        return ! collect($values)->contains(fn (mixed $value): bool => $this->cellText($value) !== '');
    }

    private function hasExtraData(array $values): bool
    {
        return collect(array_slice($values, 3))->contains(fn (mixed $value): bool => $this->cellText($value) !== '');
    }

    private function spreadsheetCellText(Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_float($value) && abs($value - round($value)) < 0.000001) {
            return $this->cellText(number_format($value, 0, '', ''));
        }

        return $this->cellText($value);
    }

    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = trim((string) $value);

        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            $value = substr($value, 3);
        }

        return trim($value);
    }

    private function phoneDigits(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    private function skippedRow(int $rowNumber, array $values, string $reason, ?string $name = null, ?string $email = null, ?string $phone = null): array
    {
        $values = array_pad(array_slice($values, 0, 3), 3, '');

        return [
            'row' => $rowNumber,
            'status' => 'skipped',
            'reason' => $reason,
            'name' => $name ?? $this->cellText($values[0]),
            'phone' => $phone ?? $this->cellText($values[1]),
            'email' => ($email ?? mb_strtolower($this->cellText($values[2]))) ?: null,
            'message' => __('app.customer_import_reason_'.$reason),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $customer
     * @return array<string, mixed>
     */
    private function alreadyFoundRow(int $rowNumber, string $name, ?string $phone, string $email, string $matchedBy, ?array $customer): array
    {
        return [
            'row' => $rowNumber,
            'status' => 'already_found',
            'reason' => $matchedBy,
            'name' => $name,
            'phone' => $phone,
            'email' => $email === '' ? null : $email,
            'matched_customer' => $customer,
            'message' => __('app.customer_import_row_already_found'),
        ];
    }

    private function isUniqueConstraintException(QueryException $exception): bool
    {
        return Str::startsWith((string) $exception->getCode(), '23');
    }
}
