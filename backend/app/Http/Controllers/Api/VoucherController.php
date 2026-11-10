<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Voucher;
use App\Services\Accounting\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class VoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Voucher::visibleTo($this->user($request))
            ->with('client:id,business_name', 'journalEntry:id,voucher_id,number')
            ->forClient($request->integer('client_id') ?: null)
            ->when($request->filled('operation'), fn ($q) => $q->where('operation', $request->operation))
            ->when($request->filled('document_type'), fn ($q) => $q->where('document_type', $request->document_type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->to))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('issuer_name', 'like', '%'.$request->search.'%')
                ->orWhere('customer_name', 'like', '%'.$request->search.'%')
                ->orWhere('issuer_ruc', 'like', '%'.$request->search.'%')
                ->orWhere('number', 'like', '%'.$request->search.'%')))
            ->orderByDesc('issue_date')->orderByDesc('id');

        $totals = (clone $q)->reorder()->selectRaw('operation, COUNT(*) as cantidad, SUM(total * exchange_rate) as total, SUM(igv * exchange_rate) as igv')
            ->groupBy('operation')->get()->keyBy('operation');

        return response()->json($q->paginate($this->perPage($request))->toArray() + ['totals' => $totals]);
    }

    public function show(Request $request, Voucher $voucher): JsonResponse
    {
        $this->ensureClientAccess($request, $voucher->client_id);

        return response()->json(['data' => $voucher->load('client:id,ruc,business_name', 'items', 'document:id,original_name,status', 'journalEntry.lines.account')]);
    }

    /** Registro manual de un comprobante (sin documento soporte digital). */
    public function store(Request $request, VoucherService $service): JsonResponse
    {
        $this->ensureInternal($request);
        $clientId = $this->resolveClientId($request);
        $data = $request->validate($this->rules());
        $result = $service->register(Client::findOrFail($clientId), $data, null, $this->user($request)->id);

        abort_if($result['errors'], 422, implode(' ', $result['errors']));

        return response()->json(['data' => $result['voucher'], 'warnings' => $result['warnings'], 'message' => 'Comprobante registrado.'], 201);
    }

    public function update(Request $request, Voucher $voucher, VoucherService $service): JsonResponse
    {
        $this->ensureInternal($request);
        $this->ensureClientAccess($request, $voucher->client_id);
        $data = $request->validate(array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->rules()));

        return response()->json(['data' => $service->update($voucher, $data, $this->user($request)->id), 'message' => 'Comprobante actualizado y asiento regenerado.']);
    }

    /** Registrar pago / cobro (tesorería, cuentas por cobrar / pagar). */
    public function payment(Request $request, Voucher $voucher): JsonResponse
    {
        $this->ensureInternal($request);
        $this->ensureClientAccess($request, $voucher->client_id);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0']]);

        $paid = round((float) $voucher->amount_paid + (float) $data['amount'], 2);
        abort_if($paid - (float) $voucher->total > 0.01, 422, 'El pago excede el saldo pendiente.');
        $voucher->update([
            'amount_paid' => $paid,
            'payment_status' => abs($paid - (float) $voucher->total) < 0.01 ? 'pagado' : 'parcial',
        ]);

        return response()->json(['data' => $voucher, 'message' => 'Pago registrado.']);
    }

    public function void(Request $request, Voucher $voucher): JsonResponse
    {
        $this->ensureInternal($request);
        $this->ensureClientAccess($request, $voucher->client_id);
        abort_if($voucher->period?->status === 'cerrado', 422, 'El periodo está cerrado.');

        $voucher->update(['status' => 'anulado']);
        $voucher->journalEntry?->update(['status' => 'anulado']);

        return response()->json(['message' => 'Comprobante anulado.']);
    }

    /**
     * "Otras fuentes": importación masiva desde Excel/CSV con columnas:
     * tipo, serie, numero, fecha, ruc_emisor, razon_social_emisor, doc_adquirente, nombre_adquirente,
     * moneda, tipo_cambio, base, exonerado, inafecto, igv, otros, total
     */
    public function import(Request $request, VoucherService $service): JsonResponse
    {
        $this->ensureInternal($request);
        $clientId = $this->resolveClientId($request);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240']]);
        $client = Client::findOrFail($clientId);

        $sheet = Excel::toArray(new \App\Imports\RawImport(),$request->file('file'))[0] ?? [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($sheet) ?? []);

        $ok = 0;
        $failed = [];
        foreach ($sheet as $i => $row) {
            if (! array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            $r = array_combine($header, array_pad($row, count($header), null));
            $data = [
                'document_type' => $r['tipo'] ?? '01',
                'series' => $r['serie'] ?? '',
                'number' => (string) ($r['numero'] ?? ''),
                'issue_date' => $this->excelDate($r['fecha'] ?? null),
                'issuer_ruc' => (string) ($r['ruc_emisor'] ?? ''),
                'issuer_name' => $r['razon_social_emisor'] ?? '',
                'customer_doc' => (string) ($r['doc_adquirente'] ?? ''),
                'customer_name' => $r['nombre_adquirente'] ?? null,
                'currency' => $r['moneda'] ?? 'PEN',
                'exchange_rate' => $r['tipo_cambio'] ?? 1,
                'taxable_amount' => $r['base'] ?? 0,
                'exempt_amount' => $r['exonerado'] ?? 0,
                'unaffected_amount' => $r['inafecto'] ?? 0,
                'igv' => $r['igv'] ?? 0,
                'other_charges' => $r['otros'] ?? 0,
                'total' => $r['total'] ?? 0,
            ];
            $result = $service->register($client, $data, null, $this->user($request)->id);
            if ($result['errors']) {
                $failed[] = ['fila' => $i + 2, 'errores' => $result['errors']];
            } else {
                $ok++;
            }
        }

        return response()->json([
            'imported' => $ok,
            'failed' => $failed,
            'message' => "{$ok} comprobante(s) importado(s)".($failed ? ', '.count($failed).' con observaciones.' : '.'),
        ]);
    }

    private function excelDate($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        $v = (string) $v;
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return date('Y-m-d', strtotime($v));
    }

    private function rules(): array
    {
        return [
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
        ];
    }
}
