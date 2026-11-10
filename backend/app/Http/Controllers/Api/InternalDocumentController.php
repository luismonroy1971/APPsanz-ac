<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** BD.3: documentos internos de SANZ & AC (manuales, políticas, plantillas...). */
class InternalDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ids = $this->user($request)->accessibleClientIds();
        $q = InternalDocument::with('uploader:id,name', 'client:id,business_name')
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhereIn('client_id', $ids)))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->search.'%'))
            ->latest();

        return response()->json($q->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:manual,politica,plantilla,normativa,general'],
            'description' => ['nullable', 'string', 'max:2000'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'file' => ['required', 'file', 'max:20480'],
        ]);
        $file = $this->storeFile($request->file('file'), 'internal');

        $doc = InternalDocument::create(collect($data)->except('file')->all() + [
            'uploaded_by' => $this->user($request)->id,
            'path' => $file['path'],
            'original_name' => $file['original_name'],
            'size' => $file['size'],
        ]);

        return response()->json(['data' => $doc, 'message' => 'Documento interno cargado.'], 201);
    }

    public function download(InternalDocument $internalDocument): StreamedResponse
    {
        return $this->downloadFile($internalDocument->path, $internalDocument->original_name);
    }

    public function destroy(Request $request, InternalDocument $internalDocument): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($internalDocument->uploaded_by === $user->id || $user->hasAnyRole(['socio', 'supervisor']), 403);
        $internalDocument->delete();

        return response()->json(['message' => 'Documento eliminado.']);
    }
}
