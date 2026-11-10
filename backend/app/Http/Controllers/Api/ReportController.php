<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Exports\TableExporter;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reportes de gestión R1 a R12. */
class ReportController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        $external = $this->user($request)->isExternal();

        return response()->json(['data' => collect(ReportService::CATALOG)
            ->filter(fn ($v, $code) => ! $external || in_array($code, ReportService::CLIENT_CODES, true))
            ->map(fn ($v, $code) => ['code' => $code, 'title' => $v[0], 'description' => $v[1], 'database' => $v[2]])
            ->values()]);
    }

    public function show(Request $request, string $code, ReportService $reports, TableExporter $exporter): JsonResponse|Response
    {
        $code = strtoupper($code);
        $user = $this->user($request);
        abort_unless(isset(ReportService::CATALOG[$code]), 404, 'Reporte no encontrado.');
        abort_if($user->isExternal() && ! in_array($code, ReportService::CLIENT_CODES, true), 403);
        if ($user->isExternal() && in_array($code, ['R5', 'R6', 'R7', 'R8'], true)) {
            abort_unless($user->hasPermissionTo('informes.ver'), 403);
        }

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'client_id' => ['nullable', 'integer'],
            'export' => ['nullable', 'in:xlsx,pdf'],
        ]);
        $report = $reports->generate($code, $user, $filters);

        if (! empty($filters['export'])) {
            return $exporter->download(
                $filters['export'],
                "{$report['code']} - {$report['title']}",
                [['title' => $report['code'], 'columns' => $report['columns'], 'rows' => $report['rows']]],
                "{$report['description']} · Del {$report['range']['from']} al {$report['range']['to']}",
                $report['summary'],
            );
        }

        return response()->json(['data' => $report]);
    }
}
