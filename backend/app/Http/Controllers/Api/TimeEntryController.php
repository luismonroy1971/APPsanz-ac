<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro de horas hombre por trabajador y cliente (R2).
 * El supervisor hace seguimiento semanal y aprueba las horas.
 */
class TimeEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $seesTeam = $user->hasAnyRole(['socio', 'supervisor']);

        $q = TimeEntry::visibleTo($user)
            ->with('user:id,name', 'client:id,business_name', 'task:id,title')
            ->forClient($request->integer('client_id') ?: null)
            ->when(! $seesTeam || $request->boolean('mine'), fn ($q) => $q->where('user_id', $user->id))
            ->when($seesTeam && $request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->to))
            ->when($request->filled('approved'), fn ($q) => $q->where('approved', $request->boolean('approved')))
            ->orderByDesc('date')->orderByDesc('id');

        $total = (clone $q)->reorder()->sum('hours');

        return response()->json($q->paginate($this->perPage($request))->toArray() + ['total_hours' => round((float) $total, 2)]);
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $this->resolveClientId($request);
        $data = $request->validate($this->rules());
        $this->checkTask($data['task_id'] ?? null, $clientId);

        $entry = TimeEntry::create($data + ['client_id' => $clientId, 'user_id' => $this->user($request)->id]);

        return response()->json(['data' => $entry->load('client:id,business_name', 'task:id,title'), 'message' => 'Horas registradas.'], 201);
    }

    public function update(Request $request, TimeEntry $timeEntry): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($timeEntry->user_id === $user->id && ! $timeEntry->approved, 403, 'Solo puede editar sus registros no aprobados.');
        $data = $request->validate($this->rules());
        $this->checkTask($data['task_id'] ?? null, $timeEntry->client_id);
        $timeEntry->update($data);

        return response()->json(['data' => $timeEntry, 'message' => 'Registro actualizado.']);
    }

    /** Aprobación (supervisor / socio), individual o masiva. */
    public function approve(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasAnyRole(['socio', 'supervisor']), 403);
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        $count = TimeEntry::visibleTo($user)->whereIn('id', $data['ids'])
            ->update(['approved' => true, 'approved_by' => $user->id]);

        return response()->json(['message' => "{$count} registro(s) aprobado(s)."]);
    }

    public function destroy(Request $request, TimeEntry $timeEntry): JsonResponse
    {
        abort_unless($timeEntry->user_id === $this->user($request)->id && ! $timeEntry->approved, 403);
        $timeEntry->delete();

        return response()->json(['message' => 'Registro eliminado.']);
    }

    private function checkTask(?int $taskId, int $clientId): void
    {
        abort_if($taskId && Task::where('id', $taskId)->value('client_id') !== $clientId, 422, 'La actividad no pertenece al cliente.');
    }

    private function rules(): array
    {
        return [
            'date' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:16'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'activity' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
