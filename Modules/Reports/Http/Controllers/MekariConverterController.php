<?php

namespace Modules\Reports\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

}
