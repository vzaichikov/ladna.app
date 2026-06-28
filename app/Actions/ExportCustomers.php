<?php

namespace App\Actions;

use App\Models\Account;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportCustomers
{
    private const HEADERS = ['name', 'phone', 'email'];

    public function customers(Account $account): StreamedResponse
    {
        $spreadsheet = $this->spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $row = 2;

        foreach ($account->customers()->select(['name', 'phone', 'email'])->orderBy('name')->orderBy('id')->cursor() as $customer) {
            $this->writeCustomerRow($spreadsheet, $row, [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ]);
            $row++;
        }

        return $this->download($spreadsheet, 'customers-export-'.now()->format('Y-m-d').'.xlsx');
    }

    public function example(): StreamedResponse
    {
        $spreadsheet = $this->spreadsheet();
        $rows = [
            ['name' => 'Olena Koval', 'phone' => '+38(063)123-12-12', 'email' => 'olena.koval@example.com'],
            ['name' => 'Maksym Import', 'phone' => '380501234567', 'email' => 'maksym.import@example.com'],
            ['name' => 'Email Only', 'phone' => '', 'email' => 'email.only@example.com'],
        ];
        $rowNumber = 2;

        foreach ($rows as $row) {
            $this->writeCustomerRow($spreadsheet, $rowNumber, $row);
            $rowNumber++;
        }

        return $this->download($spreadsheet, 'customers-import-example.xlsx');
    }

    private function spreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Customers');

        foreach (self::HEADERS as $index => $header) {
            $column = chr(65 + $index);
            $sheet->setCellValueExplicit($column.'1', $header, DataType::TYPE_STRING);
            $sheet->getColumnDimension($column)->setWidth($index === 0 ? 28 : 24);
        }

        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        return $spreadsheet;
    }

    /**
     * @param  array{name: string|null, phone: string|null, email: string|null}  $customer
     */
    private function writeCustomerRow(Spreadsheet $spreadsheet, int $row, array $customer): void
    {
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValueExplicit('A'.$row, (string) ($customer['name'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B'.$row, (string) ($customer['phone'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('C'.$row, (string) ($customer['email'] ?? ''), DataType::TYPE_STRING);
    }

    private function download(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
