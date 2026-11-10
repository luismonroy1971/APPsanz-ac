<?php

namespace App\Services\Exports;

use App\Exports\TablesExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descarga de tablas en Excel o PDF, y renderizado a binario para guardar informes.
 */
class TableExporter
{
    public function download(string $format, string $title, array $tables, ?string $subtitle = null, array $summary = []): Response
    {
        $name = Str::slug($title).'-'.now()->format('Ymd-His');

        if ($format === 'pdf') {
            return response($this->pdf($title, $tables, $subtitle, $summary), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
            ]);
        }

        return Excel::download(new TablesExport($title, $tables, $subtitle), "{$name}.xlsx");
    }

    public function pdf(string $title, array $tables, ?string $subtitle = null, array $summary = []): string
    {
        $landscape = collect($tables)->max(fn ($t) => count($t['columns'])) > 7;

        return Pdf::loadView('exports.tables', compact('title', 'tables', 'subtitle', 'summary'))
            ->setPaper('a4', $landscape ? 'landscape' : 'portrait')
            ->output();
    }

    public function xlsx(string $title, array $tables, ?string $subtitle = null): string
    {
        return Excel::raw(new TablesExport($title, $tables, $subtitle), ExcelFormat::XLSX);
    }
}
