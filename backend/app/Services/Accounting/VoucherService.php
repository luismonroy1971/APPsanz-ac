<?php

namespace App\Services\Accounting;

use App\Models\Client;
use App\Models\Period;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro de comprobantes + asiento automático.
 */
class VoucherService
{
    public function __construct(
        private readonly VoucherValidator $validator,
        private readonly JournalService $journal,
    ) {}

    /**
     * @return array{voucher: ?Voucher, errors: array<string>, warnings: array<string>}
     */
    public function register(Client $client, array $data, ?int $documentId = null, ?int $userId = null): array
    {
        $data = $this->normalize($data);
        $check = $this->validator->validate($data, $client);

        if ($check['errors']) {
            return ['voucher' => null, 'errors' => $check['errors'], 'warnings' => $check['warnings']];
        }

        $period = Period::resolve($client->id, $data['issue_date']);
        if ($period->status === 'cerrado') {
            return ['voucher' => null, 'errors' => ["El periodo {$period->label} está cerrado."], 'warnings' => $check['warnings']];
        }

        $voucher = DB::transaction(function () use ($client, $data, $documentId, $userId, $check, $period) {
            $voucher = Voucher::create(array_merge(
                collect($data)->except('items')->all(),
                [
                    'client_id' => $client->id,
                    'document_id' => $documentId,
                    'period_id' => $period->id,
                    'operation' => $check['operation'],
                    'status' => $check['warnings'] ? 'observado' : 'validado',
                    'created_by' => $userId,
                ],
            ));
            $voucher->items()->createMany($data['items'] ?? []);
            $this->journal->fromVoucher($voucher, $userId);

            return $voucher;
        });

        return ['voucher' => $voucher->load('items', 'journalEntry.lines.account'), 'errors' => [], 'warnings' => $check['warnings']];
    }

    public function update(Voucher $voucher, array $data, ?int $userId = null): Voucher
    {
        $data = $this->normalize(array_merge($voucher->only([
            'document_type', 'series', 'number', 'issue_date', 'due_date', 'issuer_ruc', 'issuer_name', 'customer_doc_type',
            'customer_doc', 'customer_name', 'currency', 'exchange_rate', 'taxable_amount', 'exempt_amount',
            'unaffected_amount', 'igv', 'isc', 'other_charges', 'total', 'reference_doc',
        ]), $data));
        $data['issue_date'] = is_string($data['issue_date']) ? $data['issue_date'] : $data['issue_date']->format('Y-m-d');

        $check = $this->validator->validate($data, $voucher->client, $voucher->id);
        if ($check['errors']) {
            throw ValidationException::withMessages(['voucher' => $check['errors']]);
        }

        return DB::transaction(function () use ($voucher, $data, $check, $userId) {
            $voucher->update(collect($data)->except('items')->all() + [
                'operation' => $check['operation'],
                'period_id' => Period::resolve($voucher->client_id, $data['issue_date'])->id,
                'status' => $check['warnings'] ? 'observado' : 'validado',
            ]);
            $this->journal->fromVoucher($voucher->fresh(), $userId);

            return $voucher->fresh(['items', 'journalEntry.lines.account']);
        });
    }

    public function normalize(array $data): array
    {
        $num = fn ($v) => round((float) str_replace(',', '', (string) ($v ?? 0)), 2);

        $data['document_type'] = str_pad((string) ($data['document_type'] ?? '01'), 2, '0', STR_PAD_LEFT);
        $data['series'] = strtoupper(trim((string) ($data['series'] ?? '')));
        $data['number'] = str_pad(ltrim(trim((string) ($data['number'] ?? '')), '0') ?: '0', 8, '0', STR_PAD_LEFT);
        $data['issuer_ruc'] = preg_replace('/\D/', '', (string) ($data['issuer_ruc'] ?? ''));
        $data['customer_doc'] = preg_replace('/\D/', '', (string) ($data['customer_doc'] ?? '')) ?: null;
        $data['customer_doc_type'] = (string) ($data['customer_doc_type'] ?? (strlen((string) $data['customer_doc']) === 8 ? '1' : '6'));
        $data['issuer_name'] = trim((string) ($data['issuer_name'] ?? '')) ?: 'SIN NOMBRE';
        $data['currency'] = strtoupper((string) ($data['currency'] ?? 'PEN')) ?: 'PEN';
        $data['exchange_rate'] = (float) ($data['exchange_rate'] ?? 1) ?: 1;
        $data['due_date'] = ($data['due_date'] ?? null) ?: null;
        $data['reference_doc'] = ($data['reference_doc'] ?? null) ?: null;
        foreach (['taxable_amount', 'exempt_amount', 'unaffected_amount', 'igv', 'isc', 'other_charges', 'total'] as $f) {
            $data[$f] = $num($data[$f] ?? 0);
        }
        $data['items'] = array_map(fn ($i) => [
            'code' => $i['code'] ?? null,
            'description' => mb_substr((string) ($i['description'] ?? 'Ítem'), 0, 500),
            'unit' => $i['unit'] ?? null,
            'quantity' => (float) ($i['quantity'] ?? 1),
            'unit_price' => (float) ($i['unit_price'] ?? 0),
            'subtotal' => $num($i['subtotal'] ?? 0),
            'igv' => $num($i['igv'] ?? 0),
            'total' => $num($i['total'] ?? 0),
        ], $data['items'] ?? []);

        return collect($data)->only([
            'document_type', 'series', 'number', 'issue_date', 'due_date', 'issuer_ruc', 'issuer_name', 'customer_doc_type',
            'customer_doc', 'customer_name', 'currency', 'exchange_rate', 'taxable_amount', 'exempt_amount',
            'unaffected_amount', 'igv', 'isc', 'other_charges', 'total', 'reference_doc', 'items',
        ])->all();
    }
}
