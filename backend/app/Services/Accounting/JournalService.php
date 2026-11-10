<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Genera y registra asientos contables (automáticos desde comprobantes o manuales).
 */
class JournalService
{
    private const BOOK_PREFIX = ['compras' => 'C', 'ventas' => 'V', 'diario' => 'D', 'caja' => 'K', 'ajustes' => 'A'];

    /**
     * Asiento automático de un comprobante:
     *  Compra: D 60 (base+exonerado+inafecto)  D 40111 (IGV)  D 64 (ISC/otros)  /  H 4212 (total)
     *  Venta:  D 1212 (total)  /  H 40111 (IGV)  H 70 (base+exonerado+inafecto+otros)
     *  Las notas de crédito invierten el asiento.
     */
    public function fromVoucher(Voucher $voucher, ?int $userId = null): JournalEntry
    {
        $acc = config('accounting.accounts');
        $rate = (float) $voucher->exchange_rate ?: 1;
        $pen = fn ($v) => round((float) $v * $rate, 2);

        $base = $pen($voucher->taxable_amount + $voucher->exempt_amount + $voucher->unaffected_amount);
        $igv = $pen($voucher->igv);
        $other = $pen($voucher->isc + $voucher->other_charges);
        $total = round($base + $igv + $other, 2);
        $ref = "{$voucher->document_type_name} {$voucher->full_number}";

        if ($voucher->operation === 'compra') {
            $lines = [
                [$acc['purchases'], $base, 0, "Compra s/ {$ref}"],
                [$acc['igv'], $igv, 0, 'IGV crédito fiscal'],
                [$acc['other_taxes'], $other, 0, 'ISC / otros cargos'],
                [$acc['payables'], 0, $total, "Por pagar a {$voucher->issuer_name}"],
            ];
            $book = 'compras';
        } else {
            $lines = [
                [$acc['receivables'], $total, 0, 'Por cobrar a '.($voucher->customer_name ?: $voucher->customer_doc)],
                [$acc['igv'], 0, $igv, 'IGV débito fiscal'],
                [$acc['sales'], 0, round($base + $other, 2), "Venta s/ {$ref}"],
            ];
            $book = 'ventas';
        }

        if ($voucher->document_type === '07') {
            $lines = array_map(fn ($l) => [$l[0], $l[2], $l[1], $l[3]], $lines);
        }

        $lines = array_values(array_filter($lines, fn ($l) => $l[1] > 0 || $l[2] > 0));

        return DB::transaction(function () use ($voucher, $lines, $book, $ref, $userId) {
            $voucher->journalEntry()?->delete();

            return $this->create([
                'client_id' => $voucher->client_id,
                'period_id' => $voucher->period_id,
                'voucher_id' => $voucher->id,
                'date' => $voucher->issue_date->format('Y-m-d'),
                'book' => $book,
                'description' => ($voucher->operation === 'compra' ? 'Registro de compra ' : 'Registro de venta ').$ref,
                'origin' => 'automatico',
                'created_by' => $userId,
            ], array_map(fn ($l) => [
                'account_id' => Account::idByCode($l[0]),
                'debit' => $l[1],
                'credit' => $l[2],
                'description' => $l[3],
            ], $lines));
        });
    }

    /**
     * @param  array<int, array{account_id:int, debit:float, credit:float, description?:string}>  $lines
     */
    public function create(array $header, array $lines): JournalEntry
    {
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        if (count($lines) < 2) {
            throw new InvalidArgumentException('El asiento debe tener al menos dos líneas.');
        }
        if (abs($debit - $credit) > 0.009) {
            throw new InvalidArgumentException(sprintf('El asiento no cuadra: Debe %.2f / Haber %.2f.', $debit, $credit));
        }

        $period = isset($header['period_id']) && $header['period_id']
            ? Period::find($header['period_id'])
            : Period::resolve($header['client_id'], $header['date']);

        if ($period->status === 'cerrado') {
            throw new InvalidArgumentException("El periodo {$period->label} está cerrado.");
        }

        return DB::transaction(function () use ($header, $lines, $debit, $credit, $period) {
            $entry = JournalEntry::create($header + [
                'period_id' => $period->id,
                'number' => $this->nextNumber($header['client_id'], $header['book'] ?? 'diario', $period),
                'total_debit' => $debit,
                'total_credit' => $credit,
            ]);
            $entry->lines()->createMany($lines);

            return $entry->load('lines.account');
        });
    }

    private function nextNumber(int $clientId, string $book, Period $period): string
    {
        $prefix = (self::BOOK_PREFIX[$book] ?? 'D').sprintf('%04d%02d', $period->year, $period->month).'-';
        $last = JournalEntry::where('client_id', $clientId)
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
