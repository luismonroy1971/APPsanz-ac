<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Training;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** R12: informes / capacitación / implementación de recomendaciones. */
class TrainingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $ids = $user->accessibleClientIds();

        $q = Training::with('client:id,business_name', 'responsible:id,name', 'creator:id,name', 'participants:id,name')
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhereIn('client_id', $ids)))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->client_id))
            ->orderByRaw('COALESCE(scheduled_date, due_date) DESC');

        return response()->json($q->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $training = DB::transaction(function () use ($data, $request) {
            $file = $request->hasFile('file') ? $this->storeFile($request->file('file'), 'trainings') : [];
            $t = Training::create(collect($data)->except(['participants', 'file'])->all() + [
                'created_by' => $this->user($request)->id,
                'path' => $file['path'] ?? null,
                'original_name' => $file['original_name'] ?? null,
            ]);
            $t->participants()->sync($data['participants'] ?? []);

            return $t;
        });

        return response()->json(['data' => $training->load('participants:id,name'), 'message' => 'Registro creado.'], 201);
    }

    public function update(Request $request, Training $training): JsonResponse
    {
        $data = $request->validate(array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->rules())
            + ['attended' => ['nullable', 'array'], 'attended.*' => ['integer']]);

        DB::transaction(function () use ($training, $data) {
            if (isset($data['status']) && in_array($data['status'], ['completado', 'implementado'], true)) {
                $data['completed_at'] = $training->completed_at ?? now();
                $data['progress'] = 100;
            }
            $training->update(collect($data)->except(['participants', 'file', 'attended'])->all());
            if (array_key_exists('participants', $data)) {
                $training->participants()->sync($data['participants'] ?? []);
            }
            if (isset($data['attended'])) {
                foreach ($training->participants as $p) {
                    $training->participants()->updateExistingPivot($p->id, ['attended' => in_array($p->id, $data['attended'], true)]);
                }
            }
        });

        return response()->json(['data' => $training->fresh(['participants:id,name', 'responsible:id,name']), 'message' => 'Registro actualizado.']);
    }

    public function download(Training $training): StreamedResponse
    {
        return $this->downloadFile($training->path, $training->original_name);
    }

    public function destroy(Training $training): JsonResponse
    {
        $training->delete();

        return response()->json(['message' => 'Registro eliminado.']);
    }

    private function rules(): array
    {
        return [
            'type' => ['required', 'in:capacitacion,informe,recomendacion'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'scheduled_date' => ['nullable', 'date'],
            'duration_hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'status' => ['nullable', 'in:planificado,en_curso,implementado,completado,cancelado'],
            'progress' => ['nullable', 'integer', 'between:0,100'],
            'responsible_id' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'participants' => ['nullable', 'array'],
            'participants.*' => ['integer', 'exists:users,id'],
            'file' => ['nullable', 'file', 'max:20480'],
        ];
    }
}
