<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkingPaper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** R9: papeles de trabajo (BD.3). */
class WorkingPaperController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = WorkingPaper::visibleTo($this->user($request))
            ->with('client:id,business_name', 'preparer:id,name', 'reviewer:id,name', 'period:id,year,month')
            ->forClient($request->integer('client_id') ?: null)
            ->when($request->filled('area'), fn ($q) => $q->where('area', $request->area))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->search.'%'))
            ->latest();

        return response()->json($q->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $clientId = $this->resolveClientId($request);
        $data = $request->validate($this->rules() + ['file' => ['nullable', 'file', 'max:20480']]);
        $file = $request->hasFile('file') ? $this->storeFile($request->file('file'), "working-papers/{$clientId}") : [];

        $paper = WorkingPaper::create(collect($data)->except('file')->all() + [
            'client_id' => $clientId,
            'prepared_by' => $this->user($request)->id,
            'path' => $file['path'] ?? null,
            'original_name' => $file['original_name'] ?? null,
        ]);

        return response()->json(['data' => $paper, 'message' => 'Papel de trabajo registrado.'], 201);
    }

    public function update(Request $request, WorkingPaper $workingPaper): JsonResponse
    {
        $this->ensureClientAccess($request, $workingPaper->client_id);
        $user = $this->user($request);
        $data = $request->validate(array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->rules())
            + ['status' => ['nullable', 'in:preparado,revisado,aprobado']]);

        if (isset($data['status']) && $data['status'] !== 'preparado') {
            abort_unless($user->hasAnyRole(['socio', 'supervisor']), 403, 'La revisión corresponde al supervisor o socio.');
            $data['reviewed_by'] = $user->id;
            $data['reviewed_at'] = now();
        }
        $workingPaper->update($data);

        return response()->json(['data' => $workingPaper->fresh(['reviewer:id,name']), 'message' => 'Papel de trabajo actualizado.']);
    }

    public function download(Request $request, WorkingPaper $workingPaper): StreamedResponse
    {
        $this->ensureClientAccess($request, $workingPaper->client_id);

        return $this->downloadFile($workingPaper->path, $workingPaper->original_name);
    }

    public function destroy(Request $request, WorkingPaper $workingPaper): JsonResponse
    {
        $this->ensureClientAccess($request, $workingPaper->client_id);
        abort_if($workingPaper->status === 'aprobado', 422, 'No se puede eliminar un papel de trabajo aprobado.');
        $workingPaper->delete();

        return response()->json(['message' => 'Papel de trabajo eliminado.']);
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:20'],
            'area' => ['required', 'in:contable,tributario,rrhh,tesoreria'],
            'period_id' => ['nullable', 'exists:periods,id'],
            'task_id' => ['nullable', 'exists:tasks,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'conclusion' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
