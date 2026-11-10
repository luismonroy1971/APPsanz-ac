<?php

namespace App\Services\Reports;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\FinancialReport;
use App\Models\InternalCommunication;
use App\Models\Message;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Training;
use App\Models\User;
use App\Models\WorkingPaper;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Reportes de gestión R1 a R12 de la propuesta APPsanz&ac.
 * Todos devuelven la misma estructura para que el frontend y los exportadores sean genéricos:
 *  { code, title, description, range, summary[], chart?, columns[], rows[] }
 */
class ReportService
{
    public const CATALOG = [
        'R1' => ['Accesos realizados', 'Al cliente / supervisor: accesos realizados por semana', 'BD.3'],
        'R2' => ['Horas hombre por cliente', 'Horas hombre trabajador SANZ & AC x CLIENTE por semana', 'BD.3'],
        'R3' => ['Documentos ingresados vs devueltos', '% documentos ingresados vs devueltos vs reingresados', 'BD.3'],
        'R4' => ['Consultas recibidas vs contestadas', '% chats recibidos vs contestados / seguimiento', 'BD.3'],
        'R5' => ['Libros contables', 'Libros contables generados por cliente', 'BD.2'],
        'R6' => ['Estados financieros periódicos', 'Estados financieros periódicos', 'BD.2'],
        'R7' => ['Estado financiero anual', 'Estado financiero anual', 'BD.2'],
        'R8' => ['Otros informes', 'Otros informes al cliente', 'BD.2'],
        'R9' => ['Papeles de trabajo', 'Papeles de trabajo por cliente y estado de revisión', 'BD.3'],
        'R10' => ['% cumplimiento R5 a R8', 'Cumplimiento de entregables R5 a R8 vs planificado', 'BD.3'],
        'R11' => ['Comunicaciones internas', 'Instrucciones / directivas / comunicaciones internas', 'BD.3'],
        'R12' => ['Capacitación y recomendaciones', 'Informes / capacitación / implementación de recomendaciones', 'BD.3'],
    ];

    /** Reportes visibles para usuarios externos (clientes). */
    public const CLIENT_CODES = ['R1', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8'];

    private User $user;
    private ?array $clientIds;
    private string $from;
    private string $to;

    public function generate(string $code, User $user, array $filters = []): array
    {
        $code = strtoupper($code);
        if (! isset(self::CATALOG[$code])) {
            throw new InvalidArgumentException("Reporte {$code} no existe.");
        }

        $this->user = $user;
        $this->clientIds = $user->accessibleClientIds();
        if (! empty($filters['client_id'])) {
            $id = (int) $filters['client_id'];
            $this->clientIds = ($this->clientIds === null || in_array($id, $this->clientIds, true)) ? [$id] : [];
        }

        $week = CarbonImmutable::now()->startOfWeek();
        $this->from = $filters['from'] ?? $week->toDateString();
        $this->to = $filters['to'] ?? $week->endOfWeek()->toDateString();

        $data = $this->{'r'.substr($code, 1)}();

        return array_merge([
            'code' => $code,
            'title' => self::CATALOG[$code][0],
            'description' => self::CATALOG[$code][1],
            'database' => self::CATALOG[$code][2],
            'range' => ['from' => $this->from, 'to' => $this->to],
            'chart' => null,
        ], $data);
    }

    private function scope(Builder $q, string $column = 'client_id'): Builder
    {
        return $this->clientIds === null ? $q : $q->whereIn($column, $this->clientIds);
    }

    private function between(Builder $q, string $column): Builder
    {
        return $q->whereBetween($column, [$this->from.' 00:00:00', $this->to.' 23:59:59']);
    }

    private function clientNames(): array
    {
        return $this->scope(Client::query(), 'id')->pluck('business_name', 'id')->all();
    }

    private static function pct(float|int $part, float|int $total): float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : 0.0;
    }

    /** R1: accesos realizados. */
    private function r1(): array
    {
        $logs = $this->between($this->scope(AccessLog::query()->with('user:id,name,type,email', 'client:id,business_name')), 'created_at')
            ->where('event', 'login')
            ->get();

        if ($this->clientIds !== null) {
            // Para clientes/analistas: solo accesos de usuarios del cliente y del equipo asignado
            $logs = $logs->filter(fn ($l) => $l->client_id !== null);
        }

        $rows = $logs->groupBy('user_id')->map(function ($g) {
            $u = $g->first()->user;

            return [
                'usuario' => $u?->name,
                'email' => $u?->email,
                'tipo' => $u?->type === 'externo' ? 'Externo (cliente)' : 'Interno',
                'cliente' => $g->first()->client?->business_name ?? 'SANZ & AC',
                'accesos' => $g->count(),
                'dias_activos' => $g->map(fn ($l) => $l->created_at->toDateString())->unique()->count(),
                'ultimo_acceso' => $g->max('created_at')?->format('Y-m-d H:i'),
            ];
        })->sortByDesc('accesos')->values();

        $byDay = [];
        foreach (CarbonPeriod::create($this->from, $this->to) as $day) {
            $d = $day->toDateString();
            $dayLogs = $logs->filter(fn ($l) => $l->created_at->toDateString() === $d);
            $byDay[] = [
                'label' => $day->locale('es')->isoFormat('ddd DD/MM'),
                'Externos' => $dayLogs->filter(fn ($l) => $l->user?->type === 'externo')->count(),
                'Internos' => $dayLogs->filter(fn ($l) => $l->user?->type !== 'externo')->count(),
            ];
        }

        return [
            'summary' => [
                ['label' => 'Accesos totales', 'value' => $logs->count()],
                ['label' => 'Usuarios activos', 'value' => $rows->count()],
                ['label' => 'Accesos de clientes', 'value' => $logs->filter(fn ($l) => $l->user?->type === 'externo')->count()],
                ['label' => 'Accesos internos', 'value' => $logs->filter(fn ($l) => $l->user?->type !== 'externo')->count()],
            ],
            'chart' => ['type' => 'bar', 'stacked' => true, 'keys' => ['Externos', 'Internos'], 'data' => count($byDay) <= 62 ? $byDay : []],
            'columns' => [
                ['key' => 'usuario', 'label' => 'Usuario'], ['key' => 'email', 'label' => 'Correo'],
                ['key' => 'tipo', 'label' => 'Tipo'], ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'accesos', 'label' => 'Accesos', 'type' => 'number'],
                ['key' => 'dias_activos', 'label' => 'Días activos', 'type' => 'number'],
                ['key' => 'ultimo_acceso', 'label' => 'Último acceso'],
            ],
            'rows' => $rows->all(),
        ];
    }

    /** R2: horas hombre trabajador x cliente. */
    private function r2(): array
    {
        $entries = $this->between($this->scope(TimeEntry::query()->with('user:id,name,position,hourly_cost', 'client:id,business_name')), 'date')->get();

        $rows = $entries->groupBy(fn ($e) => $e->user_id.'-'.$e->client_id)->map(function ($g) {
            $first = $g->first();
            $hours = round($g->sum(fn ($e) => (float) $e->hours), 2);

            return [
                'trabajador' => $first->user?->name,
                'cargo' => $first->user?->position,
                'cliente' => $first->client?->business_name,
                'horas' => $hours,
                'horas_aprobadas' => round($g->where('approved', true)->sum(fn ($e) => (float) $e->hours), 2),
                'registros' => $g->count(),
                'costo' => round($hours * (float) ($first->user?->hourly_cost ?? 0), 2),
            ];
        })->sortBy([['cliente', 'asc'], ['horas', 'desc']])->values();

        $byClient = $rows->groupBy('cliente')->map(fn ($g, $c) => ['label' => $c, 'Horas' => round($g->sum('horas'), 2)])->values()->all();

        return [
            'summary' => [
                ['label' => 'Horas registradas', 'value' => round($entries->sum(fn ($e) => (float) $e->hours), 2)],
                ['label' => 'Trabajadores', 'value' => $entries->pluck('user_id')->unique()->count()],
                ['label' => 'Clientes atendidos', 'value' => $entries->pluck('client_id')->unique()->count()],
                ['label' => 'Costo estimado (S/)', 'value' => round($rows->sum('costo'), 2), 'format' => 'money'],
            ],
            'chart' => ['type' => 'bar', 'keys' => ['Horas'], 'data' => $byClient],
            'columns' => [
                ['key' => 'trabajador', 'label' => 'Trabajador'], ['key' => 'cargo', 'label' => 'Cargo'],
                ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'horas', 'label' => 'Horas', 'type' => 'number'],
                ['key' => 'horas_aprobadas', 'label' => 'Aprobadas', 'type' => 'number'],
                ['key' => 'registros', 'label' => 'Registros', 'type' => 'number'],
                ['key' => 'costo', 'label' => 'Costo S/', 'type' => 'money'],
            ],
            'rows' => $rows->all(),
        ];
    }

    /** R3: % documentos ingresados vs devueltos vs reingresados. */
    private function r3(): array
    {
        $docs = $this->between($this->scope(Document::query()), 'documents.created_at')->get();
        $returnedIds = $this->scope(\App\Models\DocumentStatusHistory::query()
            ->join('documents', 'documents.id', '=', 'document_status_history.document_id')
            ->where('to_status', 'devuelto'), 'documents.client_id')
            ->whereBetween('document_status_history.created_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->pluck('document_id')->unique();

        $names = $this->clientNames();
        $rows = collect($names)->map(function ($name, $clientId) use ($docs, $returnedIds) {
            $d = $docs->where('client_id', $clientId);
            $ingresados = $d->whereNull('parent_id')->count();
            $reingresados = $d->whereNotNull('parent_id')->count();
            $devueltos = $d->filter(fn ($x) => $returnedIds->contains($x->id))->count();
            $procesados = $d->where('status', 'procesado')->count();

            return [
                'cliente' => $name,
                'ingresados' => $ingresados,
                'procesados' => $procesados,
                'devueltos' => $devueltos,
                'reingresados' => $reingresados,
                'pendientes' => $d->whereIn('status', ['ingresado', 'en_proceso', 'reingresado', 'observado'])->count(),
                'pct_devueltos' => self::pct($devueltos, $ingresados),
                'pct_reingresados' => self::pct($reingresados, $devueltos),
            ];
        })->filter(fn ($r) => $r['ingresados'] + $r['reingresados'] > 0)->values();

        $totIn = $rows->sum('ingresados');
        $totDev = $rows->sum('devueltos');
        $totRe = $rows->sum('reingresados');

        return [
            'summary' => [
                ['label' => 'Documentos ingresados', 'value' => $totIn],
                ['label' => '% devueltos', 'value' => self::pct($totDev, $totIn), 'format' => 'percent'],
                ['label' => '% reingresados (de devueltos)', 'value' => self::pct($totRe, $totDev), 'format' => 'percent'],
                ['label' => 'Procesados', 'value' => $rows->sum('procesados')],
            ],
            'chart' => ['type' => 'bar', 'keys' => ['Ingresados', 'Devueltos', 'Reingresados'], 'data' => $rows->map(fn ($r) => [
                'label' => $r['cliente'], 'Ingresados' => $r['ingresados'], 'Devueltos' => $r['devueltos'], 'Reingresados' => $r['reingresados'],
            ])->all()],
            'columns' => [
                ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'ingresados', 'label' => 'Ingresados', 'type' => 'number'],
                ['key' => 'procesados', 'label' => 'Procesados', 'type' => 'number'],
                ['key' => 'devueltos', 'label' => 'Devueltos', 'type' => 'number'],
                ['key' => 'reingresados', 'label' => 'Reingresados', 'type' => 'number'],
                ['key' => 'pendientes', 'label' => 'Pendientes', 'type' => 'number'],
                ['key' => 'pct_devueltos', 'label' => '% devueltos', 'type' => 'percent'],
                ['key' => 'pct_reingresados', 'label' => '% reingresados', 'type' => 'percent'],
            ],
            'rows' => $rows->all(),
        ];
    }

    /** R4: % chats recibidos vs contestados / seguimiento. */
    private function r4(): array
    {
        $convs = $this->between($this->scope(Conversation::query()), 'conversations.created_at')->get();
        $messages = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->when($this->clientIds !== null, fn ($q) => $q->whereIn('conversations.client_id', $this->clientIds))
            ->whereBetween('messages.created_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->get(['messages.*', 'conversations.client_id']);

        $names = $this->clientNames();
        $rows = collect($names)->map(function ($name, $clientId) use ($convs, $messages) {
            $c = $convs->where('client_id', $clientId);
            $m = $messages->where('client_id', $clientId);
            $answered = $c->whereNotNull('first_response_at');
            $avg = $answered->avg(fn ($x) => $x->created_at->diffInMinutes($x->first_response_at) / 60);

            return [
                'cliente' => $name,
                'consultas' => $c->count(),
                'contestadas' => $answered->count(),
                'sin_respuesta' => $c->whereNull('first_response_at')->count(),
                'en_seguimiento' => $c->where('status', 'en_seguimiento')->count(),
                'cerradas' => $c->where('status', 'cerrada')->count(),
                'mensajes_cliente' => $m->where('from_client', true)->count(),
                'mensajes_sanz' => $m->where('from_client', false)->count(),
                'pct_contestadas' => self::pct($answered->count(), $c->count()),
                'horas_primera_respuesta' => $avg !== null ? round($avg, 1) : null,
            ];
        })->filter(fn ($r) => $r['consultas'] > 0 || $r['mensajes_cliente'] > 0)->values();

        $tot = $rows->sum('consultas');
        $answeredAll = $convs->whereNotNull('first_response_at');
        $avgAll = $answeredAll->avg(fn ($x) => $x->created_at->diffInMinutes($x->first_response_at) / 60);

        return [
            'summary' => [
                ['label' => 'Consultas recibidas', 'value' => $tot],
                ['label' => '% contestadas', 'value' => self::pct($rows->sum('contestadas'), $tot), 'format' => 'percent'],
                ['label' => 'En seguimiento', 'value' => $rows->sum('en_seguimiento')],
                ['label' => 'Horas prom. 1ra respuesta', 'value' => $avgAll !== null ? round($avgAll, 1) : 0],
            ],
            'chart' => ['type' => 'bar', 'keys' => ['Recibidas', 'Contestadas', 'Seguimiento'], 'data' => $rows->map(fn ($r) => [
                'label' => $r['cliente'], 'Recibidas' => $r['consultas'], 'Contestadas' => $r['contestadas'], 'Seguimiento' => $r['en_seguimiento'],
            ])->all()],
            'columns' => [
                ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'consultas', 'label' => 'Recibidas', 'type' => 'number'],
                ['key' => 'contestadas', 'label' => 'Contestadas', 'type' => 'number'],
                ['key' => 'sin_respuesta', 'label' => 'Sin respuesta', 'type' => 'number'],
                ['key' => 'en_seguimiento', 'label' => 'Seguimiento', 'type' => 'number'],
                ['key' => 'cerradas', 'label' => 'Cerradas', 'type' => 'number'],
                ['key' => 'pct_contestadas', 'label' => '% contestadas', 'type' => 'percent'],
                ['key' => 'horas_primera_respuesta', 'label' => 'Horas 1ra resp.', 'type' => 'number'],
            ],
            'rows' => $rows->all(),
        ];
    }

    private function r5(): array { return $this->financialReports('R5'); }
    private function r6(): array { return $this->financialReports('R6'); }
    private function r7(): array { return $this->financialReports('R7'); }
    private function r8(): array { return $this->financialReports('R8'); }

    /** R5 a R8: informes contables generados/publicados (BD.2). */
    private function financialReports(string $code): array
    {
        $q = $this->scope(FinancialReport::query()->with('client:id,business_name', 'generator:id,name'))
            ->where('report_code', $code);
        $q = $this->between($q, 'financial_reports.created_at');
        if ($this->user->isExternal()) {
            $q->where('published', true);
        }
        $reports = $q->latest()->get();

        return [
            'summary' => [
                ['label' => 'Informes generados', 'value' => $reports->count()],
                ['label' => 'Publicados al cliente', 'value' => $reports->where('published', true)->count()],
                ['label' => 'En borrador / revisión', 'value' => $reports->where('published', false)->count()],
                ['label' => 'Clientes', 'value' => $reports->pluck('client_id')->unique()->count()],
            ],
            'columns' => [
                ['key' => 'cliente', 'label' => 'Cliente'], ['key' => 'titulo', 'label' => 'Informe'],
                ['key' => 'periodo', 'label' => 'Periodo'], ['key' => 'estado', 'label' => 'Estado'],
                ['key' => 'generado_por', 'label' => 'Elaborado por'], ['key' => 'fecha', 'label' => 'Fecha'],
            ],
            'rows' => $reports->map(fn ($r) => [
                'id' => $r->id,
                'cliente' => $r->client?->business_name,
                'titulo' => $r->title,
                'periodo' => $r->month ? sprintf('%04d-%02d', $r->year, $r->month) : (string) $r->year,
                'estado' => $r->published ? 'Publicado' : ucfirst($r->status),
                'generado_por' => $r->generator?->name,
                'fecha' => $r->created_at->format('Y-m-d'),
            ])->all(),
        ];
    }

    /** R9: papeles de trabajo. */
    private function r9(): array
    {
        $papers = $this->between($this->scope(WorkingPaper::query()->with('client:id,business_name', 'preparer:id,name', 'reviewer:id,name')), 'working_papers.created_at')->get();

        return [
            'summary' => [
                ['label' => 'Papeles de trabajo', 'value' => $papers->count()],
                ['label' => 'Preparados', 'value' => $papers->where('status', 'preparado')->count()],
                ['label' => 'Revisados', 'value' => $papers->where('status', 'revisado')->count()],
                ['label' => 'Aprobados', 'value' => $papers->where('status', 'aprobado')->count()],
            ],
            'chart' => ['type' => 'pie', 'keys' => ['value'], 'data' => $papers->groupBy('area')->map(fn ($g, $a) => ['label' => ucfirst($a), 'value' => $g->count()])->values()->all()],
            'columns' => [
                ['key' => 'referencia', 'label' => 'Ref.'], ['key' => 'cliente', 'label' => 'Cliente'],
                ['key' => 'titulo', 'label' => 'Papel de trabajo'], ['key' => 'area', 'label' => 'Área'],
                ['key' => 'preparado_por', 'label' => 'Preparado por'], ['key' => 'revisado_por', 'label' => 'Revisado por'],
                ['key' => 'estado', 'label' => 'Estado'],
            ],
            'rows' => $papers->map(fn ($p) => [
                'id' => $p->id,
                'referencia' => $p->reference,
                'cliente' => $p->client?->business_name,
                'titulo' => $p->title,
                'area' => ucfirst($p->area),
                'preparado_por' => $p->preparer?->name,
                'revisado_por' => $p->reviewer?->name,
                'estado' => ucfirst($p->status),
            ])->all(),
        ];
    }

    /** R10: % cumplimiento de entregables R5 a R8. */
    private function r10(): array
    {
        $tasks = $this->scope(Task::query()->with('client:id,business_name'))
            ->whereIn('deliverable', ['R5', 'R6', 'R7', 'R8'])
            ->whereBetween('due_date', [$this->from, $this->to])
            ->get();

        $rows = $tasks->groupBy(fn ($t) => $t->client_id.'-'.$t->deliverable)->map(function ($g) {
            $first = $g->first();
            $done = $g->where('status', 'completado');
            $onTime = $done->filter(fn ($t) => $t->completed_at && $t->due_date && $t->completed_at->lte($t->due_date->endOfDay()));

            return [
                'cliente' => $first->client?->business_name,
                'entregable' => $first->deliverable.' '.\App\Models\FinancialReport::CODES[$first->deliverable],
                'planificados' => $g->count(),
                'completados' => $done->count(),
                'a_tiempo' => $onTime->count(),
                'vencidos' => $g->filter(fn ($t) => $t->status !== 'completado' && $t->due_date?->isPast())->count(),
                'pct_cumplimiento' => self::pct($done->count(), $g->count()),
                'pct_a_tiempo' => self::pct($onTime->count(), $g->count()),
            ];
        })->sortBy('cliente')->values();

        $byDeliverable = $tasks->groupBy('deliverable')->map(fn ($g, $d) => [
            'label' => $d,
            'Cumplimiento' => self::pct($g->where('status', 'completado')->count(), $g->count()),
        ])->sortKeys()->values()->all();

        return [
            'summary' => [
                ['label' => 'Entregables planificados', 'value' => $tasks->count()],
                ['label' => '% cumplimiento', 'value' => self::pct($tasks->where('status', 'completado')->count(), $tasks->count()), 'format' => 'percent'],
                ['label' => '% a tiempo', 'value' => self::pct($rows->sum('a_tiempo'), $tasks->count()), 'format' => 'percent'],
                ['label' => 'Vencidos', 'value' => $rows->sum('vencidos')],
            ],
            'chart' => ['type' => 'bar', 'keys' => ['Cumplimiento'], 'data' => $byDeliverable, 'unit' => '%'],
            'columns' => [
                ['key' => 'cliente', 'label' => 'Cliente'], ['key' => 'entregable', 'label' => 'Entregable'],
                ['key' => 'planificados', 'label' => 'Planificados', 'type' => 'number'],
                ['key' => 'completados', 'label' => 'Completados', 'type' => 'number'],
                ['key' => 'a_tiempo', 'label' => 'A tiempo', 'type' => 'number'],
                ['key' => 'vencidos', 'label' => 'Vencidos', 'type' => 'number'],
                ['key' => 'pct_cumplimiento', 'label' => '% cumplimiento', 'type' => 'percent'],
                ['key' => 'pct_a_tiempo', 'label' => '% a tiempo', 'type' => 'percent'],
            ],
            'rows' => $rows->all(),
        ];
    }

    /** R11: instrucciones / directivas / comunicaciones internas. */
    private function r11(): array
    {
        $comms = InternalCommunication::query()->with('author:id,name', 'recipients:id')
            ->whereBetween('created_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->when($this->clientIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhereIn('client_id', $this->clientIds)))
            ->latest()->get();

        $rows = $comms->map(function ($c) {
            $total = $c->recipients->count();
            $read = $c->recipients->whereNotNull('pivot.read_at')->count();

            return [
                'id' => $c->id,
                'fecha' => $c->created_at->format('Y-m-d'),
                'tipo' => ucfirst($c->type),
                'titulo' => $c->title,
                'autor' => $c->author?->name,
                'destinatarios' => $total,
                'leidos' => $read,
                'confirmados' => $c->recipients->whereNotNull('pivot.acknowledged_at')->count(),
                'pct_lectura' => self::pct($read, $total),
            ];
        });

        return [
            'summary' => [
                ['label' => 'Comunicaciones', 'value' => $comms->count()],
                ['label' => 'Instrucciones', 'value' => $comms->where('type', 'instruccion')->count()],
                ['label' => 'Directivas', 'value' => $comms->where('type', 'directiva')->count()],
                ['label' => '% lectura promedio', 'value' => round((float) $rows->avg('pct_lectura'), 1), 'format' => 'percent'],
            ],
            'columns' => [
                ['key' => 'fecha', 'label' => 'Fecha'], ['key' => 'tipo', 'label' => 'Tipo'],
                ['key' => 'titulo', 'label' => 'Título'], ['key' => 'autor', 'label' => 'Autor'],
                ['key' => 'destinatarios', 'label' => 'Destinatarios', 'type' => 'number'],
                ['key' => 'leidos', 'label' => 'Leídos', 'type' => 'number'],
                ['key' => 'confirmados', 'label' => 'Confirmados', 'type' => 'number'],
                ['key' => 'pct_lectura', 'label' => '% lectura', 'type' => 'percent'],
            ],
            'rows' => $rows->all(),
        ];
    }

    /** R12: informes / capacitación / implementación de recomendaciones. */
    private function r12(): array
    {
        $items = Training::query()->with('client:id,business_name', 'responsible:id,name')->withCount('participants')
            ->where(fn ($q) => $q->whereBetween('scheduled_date', [$this->from, $this->to])
                ->orWhereBetween('due_date', [$this->from, $this->to])
                ->orWhereBetween('created_at', [$this->from.' 00:00:00', $this->to.' 23:59:59']))
            ->when($this->clientIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhereIn('client_id', $this->clientIds)))
            ->get();

        $recs = $items->where('type', 'recomendacion');

        return [
            'summary' => [
                ['label' => 'Capacitaciones', 'value' => $items->where('type', 'capacitacion')->count()],
                ['label' => 'Horas de capacitación', 'value' => round($items->where('type', 'capacitacion')->sum(fn ($t) => (float) $t->duration_hours), 1)],
                ['label' => 'Recomendaciones', 'value' => $recs->count()],
                ['label' => '% recomendaciones implementadas', 'value' => self::pct($recs->whereIn('status', ['implementado', 'completado'])->count(), $recs->count()), 'format' => 'percent'],
            ],
            'chart' => ['type' => 'pie', 'keys' => ['value'], 'data' => $items->groupBy('status')->map(fn ($g, $s) => ['label' => ucfirst($s), 'value' => $g->count()])->values()->all()],
            'columns' => [
                ['key' => 'tipo', 'label' => 'Tipo'], ['key' => 'titulo', 'label' => 'Título'],
                ['key' => 'cliente', 'label' => 'Cliente'], ['key' => 'responsable', 'label' => 'Responsable'],
                ['key' => 'fecha', 'label' => 'Fecha'], ['key' => 'participantes', 'label' => 'Participantes', 'type' => 'number'],
                ['key' => 'avance', 'label' => 'Avance', 'type' => 'percent'], ['key' => 'estado', 'label' => 'Estado'],
            ],
            'rows' => $items->map(fn ($t) => [
                'id' => $t->id,
                'tipo' => ucfirst($t->type),
                'titulo' => $t->title,
                'cliente' => $t->client?->business_name ?? 'Interno',
                'responsable' => $t->responsible?->name,
                'fecha' => ($t->scheduled_date ?? $t->due_date)?->format('Y-m-d'),
                'participantes' => $t->participants_count,
                'avance' => (float) $t->progress,
                'estado' => ucfirst($t->status),
            ])->values()->all(),
        ];
    }
}
