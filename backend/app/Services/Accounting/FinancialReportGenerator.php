<?php

namespace App\Services\Accounting;

use App\Models\Client;
use App\Models\FinancialReport;
use App\Models\Period;
use App\Models\User;
use App\Services\Exports\TableExporter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Genera informes R5 a R7 (libros y EEFF) y los guarda en BD.2 como PDF.
 */
class FinancialReportGenerator
{
    private const MONTHS = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public function __construct(
        private readonly BookTables $books,
        private readonly TableExporter $exporter,
    ) {}

    public function generate(Client $client, string $type, int $year, ?int $month, User $user, string $format = 'pdf'): FinancialReport
    {
        if ($type === 'eeff_anual') {
            $month = null;
        }
        [$code, $label] = FinancialReport::TYPES[$type];
        $built = $this->books->build($type, $client->id, $year, $month);

        $periodText = $month ? self::MONTHS[$month].' '.$year : "Ejercicio {$year}";
        $title = "{$label} - {$periodText}";
        $subtitle = "{$client->business_name} · RUC {$client->ruc} · Expresado en soles";

        $binary = $format === 'xlsx'
            ? $this->exporter->xlsx($title, $built['tables'], $subtitle)
            : $this->exporter->pdf($title, $built['tables'], $subtitle);

        $filename = Str::slug("{$label}-{$client->ruc}-{$year}".($month ? sprintf('-%02d', $month) : '')).'.'.$format;
        $path = "financial-reports/{$client->id}/".Str::uuid().'-'.$filename;
        Storage::disk('local')->put($path, $binary);

        $period = $month ? Period::firstOrCreate(['client_id' => $client->id, 'year' => $year, 'month' => $month]) : null;

        return FinancialReport::create([
            'client_id' => $client->id,
            'period_id' => $period?->id,
            'report_code' => $code,
            'type' => $type,
            'title' => $title,
            'year' => $year,
            'month' => $month,
            'format' => $format,
            'path' => $path,
            'original_name' => $filename,
            'data' => $built['data'],
            'status' => 'borrador',
            'generated_by' => $user->id,
        ]);
    }
}
