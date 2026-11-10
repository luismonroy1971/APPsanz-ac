<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Period;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S3: Proceso contable + tributario + RRHH + Tesorería (Analista + Supervisor + Socio).
 */
class TaskController extends Controller
{
    /** Plantilla mensual de actividades S3 (se genera por cliente y periodo). */
    public const TEMPLATE = [
        ['contable', 'validacion', 'Validación de documentos recibidos y registros en sistemas', null, 3, 5],
        ['contable', 'conciliaciones', 'Conciliaciones bancarias y ajustes contables', null, 4, 8],
        ['contable', 'libros', 'Elaboración y registro de libros (Compras, Ventas, Diario, Mayor)', 'R5', 6, 10],
        ['tributario', 'impuestos', 'Estimado de IGV y pago a cuenta del Impuesto a la Renta (PDT 621)', null, 2, 12],
        ['tributario', 'libros', 'Envío de Libros Electrónicos PLE a SUNAT', 'R5', 1, 12],
        ['rrhh', 'otros_informes', 'Planilla mensual, PLAME, AFP y EsSalud', null, 3, 12],
        ['tesoreria', 'impuestos', 'Programación de pagos y seguimiento de cuentas por cobrar', null, 2, 15],
        ['contable', 'eeff', 'Estados financieros del periodo', 'R6', 3, 20],
        ['contable', 'otros_informes', 'Informe de gestión mensual al cliente', 'R8', 2, 25],
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $q = Task::visibleTo($user)
            ->with('client:id,business_name', 'assignee:id,name', 'reviewer:id,name', 'period:id,year,month')
            ->withSum('timeEntries as hours_logged', 'hours')
            ->forClient($request->integer('client_id') ?: null)
            ->when($request->filled('area'), fn ($q) => $q->where('area', $request->area))
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->status)))
            ->when($request->filled('period_id'), fn ($q) => $q->where('period_id', $request->period_id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $user->id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('due_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('due_date', '<=', $request->to))
            ->orderByRaw("FIELD(status, 'en_proceso', 'pendiente', 'en_revision', 'completado')")
            ->orderBy('due_date');

        return response()->json($request->boolean('all')
            ? ['data' => $q->limit(500)->get()]
            : $q->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $this->resolveClientId($request);
        $data = $request->validate($this->rules());
        $task = Task::create($data + ['client_id' => $clientId, 'created_by' => $this->user($request)->id]);

        return response()->json(['data' => $task->load('assignee:id,name'), 'message' => 'Actividad creada.'], 201);
    }

    /** Genera la plantilla de actividades S3 del mes para un cliente. */
    public function generateMonth(Request $request): JsonResponse
    {
        $clientId = $this->resolveClientId($request);
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);
        $client = Client::with('team')->findOrFail($clientId);
        $period = Period::firstOrCreate(['client_id' => $clientId, 'year' => $data['year'], 'month' => $data['month']]);
        abort_if($period->tasks()->exists(), 422, "El periodo {$period->label} ya tiene actividades generadas.");

        $analyst = $client->team->firstWhere('pivot.role', 'analista')?->id;
        $supervisor = $client->team->firstWhere('pivot.role', 'supervisor')?->id;
        // Las actividades del mes se ejecutan durante el mes siguiente (cierre)
        $base = CarbonImmutable::create($data['year'], $data['month'], 1)->addMonth();

        DB::transaction(function () use ($period, $clientId, $analyst, $supervisor, $base, $request) {
            foreach (self::TEMPLATE as [$area, $activity, $title, $deliverable, $hours, $day]) {
                Task::create([
                    'client_id' => $clientId,
                    'period_id' => $period->id,
                    'assigned_to' => $analyst,
                    'reviewed_by' => $supervisor,
                    'created_by' => $this->user($request)->id,
                    'area' => $area,
                    'activity' => $activity,
                    'title' => $title.' - '.$period->label,
                    'deliverable' => $deliverable,
                    'estimated_hours' => $hours,
                    'due_date' => $base->setDay($day)->toDateString(),
                ]);
            }
        });

        return response()->json(['message' => count(self::TEMPLATE)." actividades generadas para {$period->label}."], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->ensureClientAccess($request, $task->client_id);
        $data = $request->validate(array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->rules()));

        if (isset($data['status'])) {
            abort_if($data['status'] === 'completado' && $task->deliverable && ! $this->user($request)->hasAnyRole(['socio', 'supervisor'])
                && $task->status !== 'en_revision', 422, 'Los entregables deben pasar por revisión del supervisor antes de completarse.');
            $data['completed_at'] = $data['status'] === 'completado' ? ($task->completed_at ?? now()) : null;
        }
        $task->update($data);

        return response()->json(['data' => $task->fresh(['assignee:id,name', 'reviewer:id,name']), 'message' => 'Actividad actualizada.']);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->ensureClientAccess($request, $task->client_id);
        abort_if($task->timeEntries()->exists(), 422, 'La actividad tiene horas registradas.');
        $task->delete();

        return response()->json(['message' => 'Actividad eliminada.']);
    }

    private function rules(): array
    {
        return [
            'area' => ['required', 'in:'.implode(',', Task::AREAS)],
            'activity' => ['required', 'in:'.implode(',', array_keys(Task::ACTIVITIES))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'deliverable' => ['nullable', 'in:R5,R6,R7,R8'],
            'status' => ['nullable', 'in:'.implode(',', Task::STATUSES)],
            'priority' => ['nullable', 'in:baja,media,alta'],
            'due_date' => ['nullable', 'date'],
            'period_id' => ['nullable', 'exists:periods,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'reviewed_by' => ['nullable', 'exists:users,id'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
        ];
    }
}
