<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\Accounting\JournalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class JournalEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = JournalEntry::visibleTo($this->user($request))
            ->with('client:id,business_name', 'lines.account:id,code,name', 'creator:id,name')
            ->forClient($request->integer('client_id') ?: null)
            ->when($request->filled('book'), fn ($q) => $q->where('book', $request->book))
            ->when($request->filled('origin'), fn ($q) => $q->where('origin', $request->origin))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->to))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('description', 'like', '%'.$request->search.'%')->orWhere('number', 'like', '%'.$request->search.'%')))
            ->orderByDesc('date')->orderByDesc('number');

        return response()->json($q->paginate($this->perPage($request)));
    }

    public function show(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->ensureClientAccess($request, $journalEntry->client_id);

        return response()->json(['data' => $journalEntry->load('lines.account', 'voucher', 'creator:id,name')]);
    }

    /** Asientos manuales: ajustes, conciliaciones, provisiones, planillas, etc. */
    public function store(Request $request, JournalService $journal): JsonResponse
    {
        $clientId = $this->resolveClientId($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'book' => ['required', 'in:diario,caja,ajustes,compras,ventas'],
            'description' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $notPostable = Account::whereIn('id', array_column($data['lines'], 'account_id'))->where('accepts_entries', false)->pluck('code');
        abort_if($notPostable->isNotEmpty(), 422, 'Las cuentas '.$notPostable->implode(', ').' son de nivel superior y no aceptan movimientos.');

        try {
            $entry = $journal->create([
                'client_id' => $clientId,
                'date' => $data['date'],
                'book' => $data['book'],
                'description' => $data['description'],
                'origin' => $data['book'] === 'ajustes' ? 'ajuste' : 'manual',
                'created_by' => $this->user($request)->id,
            ], array_map(fn ($l) => [
                'account_id' => $l['account_id'],
                'description' => $l['description'] ?? null,
                'debit' => round((float) ($l['debit'] ?? 0), 2),
                'credit' => round((float) ($l['credit'] ?? 0), 2),
            ], array_filter($data['lines'], fn ($l) => ($l['debit'] ?? 0) > 0 || ($l['credit'] ?? 0) > 0)));
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['data' => $entry, 'message' => "Asiento {$entry->number} registrado."], 201);
    }

    public function void(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->ensureClientAccess($request, $journalEntry->client_id);
        abort_if($journalEntry->origin === 'automatico', 422, 'Los asientos automáticos se anulan desde el comprobante.');
        abort_if(Period::find($journalEntry->period_id)?->status === 'cerrado', 422, 'El periodo está cerrado.');
        $journalEntry->update(['status' => 'anulado']);

        return response()->json(['message' => 'Asiento anulado.']);
    }

    public function accounts(Request $request): JsonResponse
    {
        return response()->json(['data' => Account::query()
            ->when($request->boolean('postable'), fn ($q) => $q->where('accepts_entries', true))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('code', 'like', $request->search.'%')->orWhere('name', 'like', '%'.$request->search.'%')))
            ->orderBy('code')->get()]);
    }
}
