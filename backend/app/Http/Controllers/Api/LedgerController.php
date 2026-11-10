<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Period;
use App\Services\Accounting\BookTables;
use App\Services\Accounting\LedgerService;
use App\Services\Exports\PleExporter;
use App\Services\Exports\TableExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Libros contables, estados financieros, estimados tributarios y exportaciones PLE.
 */
class LedgerController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** JSON de un libro/estado para visualizarlo en pantalla. */
    public function book(Request $request, string $type, BookTables $books): JsonResponse
    {
        [$client, $year, $month] = $this->params($request);
        abort_unless(array_key_exists($type, BookTables::BOOKS), 404);
        $built = $books->build($type, $client->id, $year, $type === 'eeff_anual' ? null : $month);

        return response()->json(['type' => $type, 'title' => $built['title'], 'data' => $built['data']]);
    }

    /** Exportar libro/estado a Excel o PDF. */
    public function export(Request $request, string $type, BookTables $books, TableExporter $exporter): Response
    {
        [$client, $year, $month] = $this->params($request);
        abort_unless(array_key_exists($type, BookTables::BOOKS), 404);
        $built = $books->build($type, $client->id, $year, $type === 'eeff_anual' ? null : $month);
        $period = $month && $type !== 'eeff_anual' ? sprintf('%02d/%04d', $month, $year) : "Ejercicio {$year}";

        return $exporter->download(
            $request->input('format', 'xlsx'),
            "{$built['title']} {$period}",
            $built['tables'],
            "{$client->business_name} · RUC {$client->ruc}",
        );
    }

    /** Archivo TXT PLE SUNAT (8.1 compras / 14.1 ventas). */
    public function ple(Request $request, PleExporter $ple): Response
    {
        [$client, $year, $month] = $this->params($request);
        abort_unless($month, 422, 'Debe indicar el mes.');
        $operation = $request->input('operation') === 'venta' ? 'venta' : 'compra';
        $file = $ple->export($client, $operation, $year, $month);

        return response($file['content'], 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    /** Estimado de impuestos del mes (IGV y pago a cuenta de renta). */
    public function taxes(Request $request): JsonResponse
    {
        [$client, $year, $month] = $this->params($request);
        abort_unless($month, 422, 'Debe indicar el mes.');

        return response()->json(['data' => $this->ledger->taxEstimate($client->id, $year, $month)]);
    }

    /** Cuentas por cobrar / por pagar pendientes. */
    public function openItems(Request $request): JsonResponse
    {
        $clientId = $this->resolveClientId($request);
        $operation = $request->input('operation') === 'compra' ? 'compra' : 'venta';

        return response()->json(['data' => $this->ledger->openItems($clientId, $operation)]);
    }

    public function periods(Request $request): JsonResponse
    {
        $clientId = $this->resolveClientId($request);

        return response()->json(['data' => Period::where('client_id', $clientId)
            ->withCount('tasks')->orderByDesc('year')->orderByDesc('month')->get()]);
    }

    public function updatePeriod(Request $request, Period $period): JsonResponse
    {
        $this->ensureClientAccess($request, $period->client_id);
        $user = $this->user($request);
        $data = $request->validate(['status' => ['required', 'in:abierto,en_revision,cerrado']]);
        abort_if($data['status'] !== 'en_revision' && ! $user->hasAnyRole(['socio', 'supervisor']), 403, 'Solo el supervisor o socio puede cerrar o reabrir periodos.');

        $period->update($data + [
            'closed_at' => $data['status'] === 'cerrado' ? now() : null,
            'closed_by' => $data['status'] === 'cerrado' ? $user->id : null,
        ]);

        return response()->json(['data' => $period, 'message' => "Periodo {$period->label} actualizado."]);
    }

    /** @return array{0: Client, 1: int, 2: ?int} */
    private function params(Request $request): array
    {
        $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);
        $clientId = $this->resolveClientId($request);

        return [Client::findOrFail($clientId), $request->integer('year'), $request->integer('month') ?: null];
    }
}
