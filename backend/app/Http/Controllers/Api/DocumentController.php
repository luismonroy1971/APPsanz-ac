<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Services\Documents\DocumentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    private const ALLOWED = 'xml,zip,pdf,jpg,jpeg,png,webp,xlsx,xls,csv,doc,docx,txt';

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $q = Document::visibleTo($user)
            ->with('client:id,ruc,business_name', 'uploader:id,name,type', 'assignee:id,name', 'voucher:id,document_id,series,number,total,currency,operation')
            ->forClient($request->integer('client_id') ?: null)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->status)))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('period'), fn ($q) => $q->where('period', $request->period))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $user->id))
            ->when($request->filled('search'), fn ($q) => $q->where('original_name', 'like', '%'.$request->search.'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest();

        $stats = Document::visibleTo($user)->forClient($request->integer('client_id') ?: null)
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return response()->json($q->paginate($this->perPage($request))->toArray() + ['stats' => $stats]);
    }

    /** C1: el cliente (o el analista por "otras fuentes") ingresa documentos. */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:30'],
            'files.*' => ['file', 'max:20480', 'mimes:'.self::ALLOWED],
            'category' => ['required', 'in:'.implode(',', Document::CATEGORIES)],
            'period' => ['nullable', 'date_format:Y-m'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $this->user($request);
        $clientId = $this->resolveClientId($request);

        $created = [];
        $duplicates = [];
        foreach ($request->file('files') as $file) {
            $hash = hash_file('sha256', $file->getRealPath());
            $existing = Document::where('client_id', $clientId)->where('hash', $hash)
                ->whereNotIn('status', ['devuelto', 'error'])->first();
            if ($existing) {
                $duplicates[] = $file->getClientOriginalName();
                continue;
            }

            $stored = $this->storeFile($file, "documents/{$clientId}");
            $doc = Document::create($stored + [
                'client_id' => $clientId,
                'uploaded_by' => $user->id,
                'category' => $request->category,
                'source' => $user->isExternal() ? 'portal' : 'otras_fuentes',
                'period' => $request->period,
                'client_notes' => $request->notes,
                'status' => 'ingresado',
            ]);
            $doc->history()->create(['user_id' => $user->id, 'to_status' => 'ingresado', 'comment' => 'Documento ingresado.']);
            ProcessDocumentJob::dispatchAfterResponse($doc->id);
            $created[] = $doc;
        }

        $msg = count($created).' documento(s) recibido(s).';
        if ($duplicates) {
            $msg .= ' Omitidos por duplicados: '.implode(', ', $duplicates).'.';
        }

        return response()->json(['data' => $created, 'duplicates' => $duplicates, 'message' => $msg], 201);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $this->ensureClientAccess($request, $document->client_id);
        $document->load('client:id,ruc,business_name', 'uploader:id,name,type', 'assignee:id,name', 'history.user:id,name',
            'parent:id,original_name,status,return_reason', 'resubmissions:id,parent_id,original_name,status,created_at',
            'voucher.items', 'voucher.journalEntry.lines.account');

        return response()->json(['data' => $document]);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->ensureClientAccess($request, $document->client_id);

        return $this->downloadFile($document->path, $document->original_name);
    }

    /** S1: procesar (o reprocesar) automáticamente. */
    public function process(Request $request, Document $document, DocumentProcessor $processor): JsonResponse
    {
        $this->ensureClientAccess($request, $document->client_id);
        abort_if($document->status === 'procesado', 422, 'El documento ya fue procesado.');

        $doc = $processor->process($document, $this->user($request));

        return response()->json(['data' => $doc->fresh(['voucher', 'history.user']), 'message' => 'Procesamiento ejecutado: '.$doc->status.'.']);
    }

    /** Registro manual / corrección de datos extraídos por el analista. */
    public function register(Request $request, Document $document, DocumentProcessor $processor): JsonResponse
    {
        $this->ensureClientAccess($request, $document->client_id);
        abort_if($document->voucher()->exists(), 422, 'El documento ya tiene un comprobante registrado.');

        $data = $request->validate([
            'document_type' => ['required', 'in:01,03,07,08'],
            'series' => ['required', 'string', 'max:4'],
            'number' => ['required', 'string', 'max:8'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'issuer_ruc' => ['required', 'digits:11'],
            'issuer_name' => ['required', 'string', 'max:255'],
            'customer_doc' => ['nullable', 'string', 'max:15'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'in:PEN,USD,EUR'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'taxable_amount' => ['required', 'numeric'],
            'exempt_amount' => ['nullable', 'numeric'],
            'unaffected_amount' => ['nullable', 'numeric'],
            'igv' => ['required', 'numeric'],
            'isc' => ['nullable', 'numeric'],
            'other_charges' => ['nullable', 'numeric'],
            'total' => ['required', 'numeric', 'gt:0'],
            'reference_doc' => ['nullable', 'string', 'max:30'],
        ]);

        $doc = $processor->register($document, $data, $document->processing_method === 'ia' ? 'ia' : 'manual', $this->user($request));

        abort_if($doc->status === 'observado', 422, implode(' ', $doc->validation_errors ?? ['Datos inconsistentes.']));

        return response()->json(['data' => $doc, 'message' => 'Comprobante registrado.']);
    }

    /** El analista marca un documento no transaccional como procesado. */
    public function complete(Request $request, Document $document): JsonResponse
    {
        $this->ensureClientAccess($request, $document->client_id);
        $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $document->update(['processed_at' => now(), 'processing_method' => $document->processing_method ?? 'manual']);
        $document->transitionTo('procesado', $this->user($request), $request->comment ?: 'Revisado y procesado por el analista.');

        return response()->json(['data' => $document, 'message' => 'Documento marcado como procesado.']);
    }

    /** Devolver al cliente con motivo (genera indicador R3). */
    public function return(Request $request, Document $document): JsonResponse
    {
        $this->ensureClientAccess($request, $document->client_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        abort_if($document->status === 'procesado', 422, 'No se puede devolver un documento ya procesado.');

        $document->update(['return_reason' => $data['reason'], 'returned_at' => now()]);
        $document->transitionTo('devuelto', $this->user($request), $data['reason']);

        return response()->json(['data' => $document, 'message' => 'Documento devuelto al cliente.']);
    }

    /** Reingreso de un documento devuelto (el cliente sube la versión corregida). */
    public function resubmit(Request $request, Document $document): JsonResponse
    {
        $this->ensureClientAccess($request, $document->client_id);
        abort_unless($document->status === 'devuelto', 422, 'Solo se pueden reingresar documentos devueltos.');
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:'.self::ALLOWED],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $this->user($request);

        $stored = $this->storeFile($request->file('file'), "documents/{$document->client_id}");
        $new = Document::create($stored + [
            'client_id' => $document->client_id,
            'uploaded_by' => $user->id,
            'parent_id' => $document->id,
            'assigned_to' => $document->assigned_to,
            'category' => $document->category,
            'source' => $document->source,
            'period' => $document->period,
            'client_notes' => $request->notes,
            'status' => 'reingresado',
        ]);
        $new->history()->create(['user_id' => $user->id, 'to_status' => 'reingresado', 'comment' => "Reingreso del documento #{$document->id}."]);
        $document->history()->create(['user_id' => $user->id, 'from_status' => 'devuelto', 'to_status' => 'devuelto', 'comment' => "Reingresado como documento #{$new->id}."]);
        ProcessDocumentJob::dispatchAfterResponse($new->id);

        return response()->json(['data' => $new, 'message' => 'Documento reingresado.'], 201);
    }

    public function assign(Request $request, Document $document): JsonResponse
    {
        $this->ensureClientAccess($request, $document->client_id);
        $data = $request->validate(['assigned_to' => ['nullable', 'exists:users,id']]);
        $document->update($data);

        return response()->json(['data' => $document->load('assignee:id,name'), 'message' => 'Responsable asignado.']);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->ensureClientAccess($request, $document->client_id);
        $user = $this->user($request);
        abort_unless(
            $document->status === 'ingresado' && ($document->uploaded_by === $user->id || $user->hasAnyRole(['socio', 'supervisor'])),
            422, 'Solo se pueden eliminar documentos recién ingresados.'
        );
        $document->delete();

        return response()->json(['message' => 'Documento eliminado.']);
    }
}
