<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MekariConverterController extends Controller
{
    public function convertMekariReport()
    {
        abort_if(Gate::denies('access_reports'), 403);

        return view('reports::mekari-converter.index');
    }

    public function handleMekariReport(Request $request)
    {
        abort_if(Gate::denies('access_reports'), 403);

        $request->validate([
            'report_file' => 'required|file|mimes:csv,txt',
        ]);

        $file = $request->file('report_file');
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_map('trim', array_shift($rows));

        $data = array_map(function ($row) use ($header) {
            return array_combine($header, array_map('trim', $row));
        }, $rows);

        $filtered = [];
        $count = count($data);

        for ($i = 0; $i < $count; $i++) {
            $row = $data[$i];
            $isPajak = stripos($row['Produk'], 'pajak') !== false;

            if ($isPajak) {
                $filtered[] = $row;
                continue;
            }

            if (
                $i + 1 < $count &&
                stripos($data[$i + 1]['Produk'], 'pajak') !== false &&
                $row['No'] === $data[$i + 1]['No']
            ) {
                $filtered[] = $row;
                $filtered[] = $data[$i + 1];
                $i++;
            }
        }

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $filename = 'filtered_' . $originalName . '.csv';

        return new StreamedResponse(function () use ($filtered, $header) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $header);
            foreach ($filtered as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function showForm()
    {
        abort_if(Gate::denies('access_reports'), 403);
        return view('reports::mekari-invoice-generator.index');
    }

    public function generate(Request $request)
    {
        abort_if(Gate::denies('access_reports'), 403);

        $request->validate([
            'sales_csv' => 'required|file|mimes:csv,txt',
            'contacts_csv' => 'required|file|mimes:csv,txt',
        ]);

        // Parse uploaded sales file
        $sales = collect(array_map('str_getcsv', file($request->file('sales_csv')->getRealPath())));
        $salesHeaders = array_map('trim', $sales->shift());
        $sales = $sales->map(function ($row) use ($salesHeaders) {
            $clean = array_combine($salesHeaders, array_map('trim', $row));

            // Clean specific fields
            foreach (['Harga Satuan', 'Jumlah Tagihan', 'Kuantitas'] as $field) {
                if (isset($clean[$field])) {
                    $clean[$field] = floatval(preg_replace('/[^\d.]/', '', $clean[$field]));
                }
            }

            return $clean;
        });

        // Parse uploaded contacts file
        $contacts = collect(array_map('str_getcsv', file($request->file('contacts_csv')->getRealPath())));
        $contactHeaders = array_map('trim', $contacts->shift());
        $contacts = $contacts->filter(function ($row) use ($contactHeaders) {
            return count($row) === count($contactHeaders);
        })->map(function ($row) use ($contactHeaders) {
            return array_combine($contactHeaders, array_map('trim', $row));
        });

        $salesByInvoice = $sales->groupBy('No');
        $pdfDir = storage_path('app/temp_invoices_' . Str::uuid());
        mkdir($pdfDir);

        foreach ($salesByInvoice as $invoiceNo => $rows) {
            $customerName = $rows->first()['Pelanggan'];
            $customer = $contacts->firstWhere('*DisplayName', $customerName);

            $pdf = \PDF::loadView('reports::mekari-invoice-generator.invoice-pdf', [
                'invoiceNo' => $invoiceNo,
                'invoiceDate' => $rows->first()['Tanggal'],
                'customer' => $customer,
                'items' => $rows->reject(fn($r) => str_contains(strtolower($r['Produk']), 'pajak')),
                'taxes' => $rows->filter(fn($r) => str_contains(strtolower($r['Produk']), 'pajak')),
                'total' => $rows->sum('Jumlah Tagihan'),
            ]);

            $pdf->save("$pdfDir/$invoiceNo.pdf");
        }

        // Zip the PDFs
        $zipPath = storage_path("app/public/invoices_" . now()->format('Ymd_His') . ".zip");
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach (glob("$pdfDir/*.pdf") as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        // Clean up temp folder
        array_map('unlink', glob("$pdfDir/*.pdf"));
        rmdir($pdfDir);

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    public function handleXlsxReport(Request $request)
    {
        abort_if(Gate::denies('access_reports'), 403);

        try {
            $request->validate([
                'xlsx_file' => 'required|file|mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream',
            ]);

            $file = $request->file('xlsx_file');
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

            // Ensure file has at least 6 rows for headers
            if (count($rows) < 6) {
                return back()->withErrors(['xlsx_file' => 'File terlalu pendek atau tidak sesuai format.']);
            }

            $header = array_map('trim', array_values($rows[6]));
            Log::info('Extracted header from row 6:', $header);

            if (!in_array('Produk', $header) || !in_array('Jumlah Tagihan', $header)) {
                return back()->withErrors(['xlsx_file' => 'Kolom "Produk" atau "Jumlah Tagihan" tidak ditemukan di header.']);
            }

            $dataRows = array_slice($rows, 6);

            $data = array_map(function ($row) use ($header) {
                $values = array_map('trim', array_values($row));
                if (count($values) !== count($header)) {
                    Log::warning('Skipping row due to mismatched column count', ['row' => $row]);
                    return null;
                }
                return array_combine($header, $values);
            }, $dataRows);

            $data = array_filter($data); // remove null rows

            // Group by customer
            $groupedByCustomer = [];
            $currentCustomer = null;
            $currentGroup = [];

            foreach ($data as $row) {
                $produk = $row['Produk'] ?? '';

                if (preg_match('/^\((.+?)\)\s*\|\s*Total Penjualan$/i', $produk, $matches)) {
                    if ($currentCustomer && count($currentGroup)) {
                        $groupedByCustomer[$currentCustomer] = $currentGroup;
                        Log::info("Grouped customer: $currentCustomer with " . count($currentGroup) . " rows");
                    }

                    $currentCustomer = trim($matches[1]);
                    $currentGroup = [$row];
                } else {
                    $currentGroup[] = $row;
                }
            }

            if ($currentCustomer && count($currentGroup)) {
                $groupedByCustomer[$currentCustomer] = $currentGroup;
                Log::info("Grouped customer (final): $currentCustomer with " . count($currentGroup) . " rows");
            }

            // Filter logic
            $filtered = [];

            foreach ($groupedByCustomer as $customer => $rows) {
                $keepRows = [];
                $count = count($rows);

                for ($i = 0; $i < $count; $i++) {
                    $row = $rows[$i];
                    $produkVal = $row['Produk'] ?? '';
                    $isTotalRow = preg_match('/^\(.+\)\s*\|\s*Total Penjualan$/i', $produkVal);

                    if ($isTotalRow) {
                        $keepRows[] = $row;
                        continue;
                    }

                    $isPajak = stripos($produkVal, 'pajak') !== false;
                    Log::debug("Customer [$customer] Row [$i]: Produk = '$produkVal' | isPajak = " . ($isPajak ? 'YES' : 'NO'));

                    if ($isPajak) {
                        $keepRows[] = $row;
                        continue;
                    }

                    if (
                        $i + 1 < $count &&
                        stripos($rows[$i + 1]['Produk'] ?? '', 'pajak') !== false &&
                        ($row['No'] ?? null) === ($rows[$i + 1]['No'] ?? null)
                    ) {
                        $keepRows[] = $row;
                        $keepRows[] = $rows[++$i];
                    }
                }

                $totalPajak = collect($keepRows)
                    ->filter(fn($r) => stripos($r['Produk'], 'pajak') !== false)
                    ->sum(fn($r) => floatval(preg_replace('/[^\d.]/', '', $r['Jumlah Tagihan'] ?? 0)));

                Log::info("Customer [$customer] Total Pajak = $totalPajak, Kept Rows = " . count($keepRows));

                // 🧪 DEBUG MODE: Temporarily bypass filter to force content
                $filtered = array_merge($filtered, $keepRows);

                // 🧪 Switch this back to enable filtering:
                // if ($totalPajak > 0) {
                //     $filtered = array_merge($filtered, $keepRows);
                // }
            }

            if (empty($filtered)) {
                Log::warning('No rows matched filter — empty XLSX will be generated.');
            }

            // Generate XLSX
            $newSpreadsheet = new Spreadsheet();
            $newSheet = $newSpreadsheet->getActiveSheet();

            $newSheet->fromArray($header, null, 'A1');

            $rowIndex = 2;
            foreach ($filtered as $row) {
                $newSheet->fromArray(array_values($row), null, "A{$rowIndex}");
                $rowIndex++;
            }

            $filename = 'filtered_with_pajak_' . pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.xlsx';

            return response()->streamDownload(function () use ($newSpreadsheet) {
                $writer = new Xlsx($newSpreadsheet);
                $writer->save('php://output');
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in handleXlsxReport: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['xlsx_file' => 'Terjadi kesalahan saat memproses file. Silakan periksa kembali format file Anda.']);
        }
    }

    public function convertFilteredCsvToFormattedXlsx(Request $request)
    {
        $request->validate([
            'filtered_csv' => 'required|file|mimes:csv,txt',
        ]);

        $file = $request->file('filtered_csv');
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_map('trim', array_shift($rows));
        $data = array_map(function ($row) use ($header) {
            return array_combine($header, array_map('trim', $row));
        }, $rows);

        // Sort by Pelanggan, Tanggal, No
        usort($data, function ($a, $b) {
            return [$a['Pelanggan'], $a['Tanggal'], $a['No']] <=> [$b['Pelanggan'], $b['Tanggal'], $b['No']];
        });

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Merge A1 to J5
        for ($i = 1; $i <= 5; $i++) {
            $sheet->mergeCells("A{$i}:J{$i}");
            $sheet->getStyle("A{$i}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Header row (row 6)
        $customHeader = [
            "Pelanggan / Tanggal", "Transaksi", "No", "Produk", "Keterangan",
            "Kuantitas", "Satuan", "Harga Satuan", "Jumlah Tagihan", "Total"
        ];
        foreach ($customHeader as $col => $value) {
            $sheet->setCellValueByColumnAndRow($col + 1, 6, $value);
        }

        $rowIndex = 7;
        $currentCustomer = null;
        $customerSubtotal = 0;
        $grandTotal = 0;

        foreach ($data as $row) {
            $isNewCustomer = $currentCustomer !== $row['Pelanggan'];

            if ($isNewCustomer && $currentCustomer !== null) {
                // Subtotal row for previous customer
                $sheet->setCellValue("I{$rowIndex}", "({$currentCustomer}) | Total Penjualan");
                $sheet->setCellValue("J{$rowIndex}", $customerSubtotal);
                $rowIndex++;
                $customerSubtotal = 0;
            }

            $jumlahTagihan = floatval(preg_replace('/[^\d.]/', '', $row['Jumlah Tagihan'] ?? 0));
            $customerSubtotal += $jumlahTagihan;
            $grandTotal += $jumlahTagihan;

            $sheet->setCellValue("A{$rowIndex}", "{$row['Pelanggan']} / {$row['Tanggal']}");
            $sheet->setCellValue("B{$rowIndex}", "Sales Invoice");
            $sheet->setCellValue("C{$rowIndex}", $row['No']);
            $sheet->setCellValue("D{$rowIndex}", $row['Produk']);
            $sheet->setCellValue("E{$rowIndex}", $row['Keterangan']);
            $sheet->setCellValue("F{$rowIndex}", $row['Kuantitas']);
            $sheet->setCellValue("G{$rowIndex}", $row['Satuan']);
            $sheet->setCellValue("H{$rowIndex}", $row['Harga Satuan']);
            $sheet->setCellValue("I{$rowIndex}", $jumlahTagihan);
            $sheet->setCellValue("J{$rowIndex}", $customerSubtotal);

            $currentCustomer = $row['Pelanggan'];
            $rowIndex++;
        }

        // Final customer subtotal
        if ($currentCustomer !== null) {
            $sheet->setCellValue("I{$rowIndex}", "({$currentCustomer}) | Total Penjualan");
            $sheet->setCellValue("J{$rowIndex}", $customerSubtotal);
            $rowIndex++;
        }

        // Grand total row
        $sheet->setCellValue("I{$rowIndex}", "Grand Total");
        $sheet->setCellValue("J{$rowIndex}", $grandTotal);

        $filename = 'formatted_sales_report_' . now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
