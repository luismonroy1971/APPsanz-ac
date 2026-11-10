<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FinancialReport;
use App\Services\Accounting\FinancialReportGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BD.2: informes R5 a R8 (libros, EEFF periódicos, EEFF anual, otros informes).
 */
class FinancialReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $q = FinancialReport::visibleTo($user)
            ->with('client:id,business_name', 'generator:id,name', 'reviewer:id,name')
            ->forClient($request->integer('client_id') ?: null)
            ->when($user->isExternal(), fn ($q) => $q->where('published', true))
            ->when($request->filled('report_code'), fn ($q) => $q->where('report_code', $request->report_code))
            ->when($request->filled('year'), fn ($q) => $q->where('year', $request->year))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('year')->orderByDesc('month')->latest();

        return response()->json($q->paginate($this->perPage($request)));
    }

    /** Genera R5/R6/R7 a partir de la contabilidad registrada. */
    public function generate(Request $request, FinancialReportGenerator $generator): JsonResponse
    {
        $this->ensureInternal($request);
        $clientId = $this->resolveClientId($request);
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(array_diff_key(FinancialReport::TYPES, ['otro' => 1])))],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'required_unless:type,eeff_anual', 'integer', 'between:1,12'],
            'format' => ['nullable', 'in:pdf,xlsx'],
        ]);

        $report = $generator->generate(Client::findOrFail($clientId), $data['type'], $data['year'], $data['month'] ?? null,
            $this->user($request), $data['format'] ?? 'pdf');

        return response()->json(['data' => $report, 'message' => "Informe \"{$report->title}\" generado."], 201);
    }

    /** R8 (u otro): sube un informe elaborado fuera del sistema. */
    public function store(Request $request): JsonResponse
    {
        $this->ensureInternal($request);
        $clientId = $this->resolveClientId($request);
        $data = $request->validate([
            'report_code' => ['required', 'in:R5,R6,R7,R8'],
            'title' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,xlsx,xls,docx,doc,zip'],
        ]);
        $stored = $this->storeFile($request->file('file'), "financial-reports/{$clientId}");

        $report = FinancialReport::create([
            'client_id' => $clientId,
            'report_code' => $data['report_code'],
            'type' => 'otro',
            'title' => $data['title'],
            'year' => $data['year'],
            'month' => $data['month'] ?? null,
            'format' => $stored['extension'],
            'path' => $stored['path'],
            'original_name' => $stored['original_name'],
            'status' => 'borrador',
            'generated_by' => $this->user($request)->id,
        ]);

        return response()->json(['data' => $report, 'message' => 'Informe cargado.'], 201);
    }

    /** Flujo de revisión: borrador -> revisado (supervisor) -> publicado al cliente. */
    public function update(Request $request, FinancialReport $financialReport): JsonResponse
    {
        $this->ensureInternal($request);
        $this->ensureClientAccess($request, $financialReport->client_id);
        $user = $this->user($request);
        $data = $request->validate(['action' => ['required', 'in:review,publish,unpublish']]);

        if ($data['action'] !== 'review') {
            abort_unless($user->hasAnyRole(['socio', 'supervisor']), 403, 'Solo el supervisor o socio puede publicar informes al cliente.');
        }

        match ($data['action']) {
            'review' => $financialReport->update(['status' => 'revisado', 'reviewed_by' => $user->id]),
            'publish' => $financialReport->update(['status' => 'publicado', 'published' => true, 'published_at' => now(), 'reviewed_by' => $financialReport->reviewed_by ?? $user->id]),
            'unpublish' => $financialReport->update(['status' => 'revisado', 'published' => false, 'published_at' => null]),
        };

        return response()->json(['data' => $financialReport->fresh(['reviewer:id,name']), 'message' => 'Informe actualizado.']);
    }

    public function download(Request $request, FinancialReport $financialReport): StreamedResponse
    {
        $this->ensureClientAccess($request, $financialReport->client_id);
        abort_if($this->user($request)->isExternal() && ! $financialReport->published, 404);

        return $this->downloadFile($financialReport->path, $financialReport->original_name);
    }

    public function destroy(Request $request, FinancialReport $financialReport): JsonResponse
    {
        $this->ensureInternal($request);
        $this->ensureClientAccess($request, $financialReport->client_id);
        abort_if($financialReport->published, 422, 'Despublique el informe antes de eliminarlo.');
        $financialReport->delete();

        return response()->json(['message' => 'Informe eliminado.']);
    }
}
