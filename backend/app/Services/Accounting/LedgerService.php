<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Libros contables (R5) y estados financieros (R6/R7) a partir de comprobantes y asientos.
 */
class LedgerService
{
    /** @return array{0: string, 1: string} */
    public static function range(int $year, ?int $month = null): array
    {
        if ($month) {
            $d = CarbonImmutable::create($year, $month, 1);

            return [$d->startOfMonth()->toDateString(), $d->endOfMonth()->toDateString()];
        }

        return ["{$year}-01-01", "{$year}-12-31"];
    }

    /** Registro de Compras / Registro de Ventas. */
    public function salesOrPurchases(int $clientId, string $operation, string $from, string $to): array
    {
        $rows = Voucher::where('client_id', $clientId)
            ->where('operation', $operation)
            ->whereBetween('issue_date', [$from, $to])
            ->where('status', '!=', 'anulado')
            ->orderBy('issue_date')->orderBy('series')->orderBy('number')
            ->get();

        $items = $rows->values()->map(fn (Voucher $v, $i) => [
            'correlativo' => $i + 1,
            'id' => $v->id,
            'fecha' => $v->issue_date->format('Y-m-d'),
            'vencimiento' => $v->due_date?->format('Y-m-d'),
            'tipo' => $v->document_type,
            'serie' => $v->series,
            'numero' => $v->number,
            'doc_contraparte' => $v->counterpartDoc(),
            'contraparte' => $v->counterpartName(),
            'moneda' => $v->currency,
            'tipo_cambio' => (float) $v->exchange_rate,
            'base_imponible' => $v->signedPen('taxable_amount'),
            'exonerado' => $v->signedPen('exempt_amount'),
            'inafecto' => $v->signedPen('unaffected_amount'),
            'igv' => $v->signedPen('igv'),
            'otros' => round($v->signedPen('isc') + $v->signedPen('other_charges'), 2),
            'total' => $v->signedPen('total'),
            'referencia' => $v->reference_doc,
            'estado' => $v->status,
        ]);

        $totals = [];
        foreach (['base_imponible', 'exonerado', 'inafecto', 'igv', 'otros', 'total'] as $f) {
            $totals[$f] = round($items->sum($f), 2);
        }

        return ['rows' => $items->all(), 'totals' => $totals];
    }

    /** Libro Diario. */
    public function journal(int $clientId, string $from, string $to): array
    {
        $entries = JournalEntry::with('lines.account')
            ->where('client_id', $clientId)
            ->whereBetween('date', [$from, $to])
            ->where('status', 'registrado')
            ->orderBy('date')->orderBy('number')
            ->get();

        return [
            'entries' => $entries->map(fn (JournalEntry $e) => [
                'id' => $e->id,
                'numero' => $e->number,
                'fecha' => $e->date->format('Y-m-d'),
                'libro' => $e->book,
                'glosa' => $e->description,
                'lineas' => $e->lines->map(fn ($l) => [
                    'cuenta' => $l->account->code,
                    'denominacion' => $l->account->name,
                    'glosa' => $l->description,
                    'debe' => (float) $l->debit,
                    'haber' => (float) $l->credit,
                ])->all(),
            ])->all(),
            'totals' => [
                'debe' => round((float) $entries->sum('total_debit'), 2),
                'haber' => round((float) $entries->sum('total_credit'), 2),
            ],
        ];
    }

    /** Libro Mayor: movimientos por cuenta con saldo acumulado. */
    public function ledger(int $clientId, string $from, string $to, ?string $accountCode = null): array
    {
        $opening = $this->balancesQuery($clientId, null, date('Y-m-d', strtotime($from.' -1 day')))
            ->get()->keyBy('account_id');

        $lines = JournalEntryLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->where('je.client_id', $clientId)
            ->where('je.status', 'registrado')
            ->whereBetween('je.date', [$from, $to])
            ->when($accountCode, fn ($q) => $q->where('a.code', 'like', $accountCode.'%'))
            ->orderBy('a.code')->orderBy('je.date')->orderBy('je.number')
            ->get([
                'journal_entry_lines.*', 'je.date', 'je.number', 'je.description as entry_description',
                'a.code', 'a.name', 'a.nature',
            ])
            ->groupBy('account_id');

        $accounts = [];
        foreach ($lines as $accountId => $group) {
            $first = $group->first();
            $sign = $first->nature === 'deudora' ? 1 : -1;
            $open = $opening->get($accountId);
            $balance = $open ? $sign * ((float) $open->debit - (float) $open->credit) : 0.0;
            $initial = round($balance, 2);

            $movements = $group->map(function ($l) use (&$balance, $sign) {
                $balance += $sign * ((float) $l->debit - (float) $l->credit);

                return [
                    'fecha' => substr((string) $l->date, 0, 10),
                    'asiento' => $l->number,
                    'glosa' => $l->description ?: $l->entry_description,
                    'debe' => (float) $l->debit,
                    'haber' => (float) $l->credit,
                    'saldo' => round($balance, 2),
                ];
            })->values()->all();

            $accounts[] = [
                'cuenta' => $first->code,
                'denominacion' => $first->name,
                'naturaleza' => $first->nature,
                'saldo_inicial' => $initial,
                'movimientos' => $movements,
                'total_debe' => round($group->sum(fn ($l) => (float) $l->debit), 2),
                'total_haber' => round($group->sum(fn ($l) => (float) $l->credit), 2),
                'saldo_final' => round($balance, 2),
            ];
        }

        return ['accounts' => $accounts];
    }

    /** Balance de comprobación (sumas y saldos). */
    public function trialBalance(int $clientId, string $from, string $to): array
    {
        $rows = $this->balancesQuery($clientId, $from, $to)->orderBy('a.code')->get();

        $items = $rows->map(function ($r) {
            $debit = round((float) $r->debit, 2);
            $credit = round((float) $r->credit, 2);
            $diff = round($debit - $credit, 2);

            return [
                'cuenta' => $r->code,
                'denominacion' => $r->name,
                'debe' => $debit,
                'haber' => $credit,
                'saldo_deudor' => $diff > 0 ? $diff : 0,
                'saldo_acreedor' => $diff < 0 ? -$diff : 0,
            ];
        });

        $totals = [];
        foreach (['debe', 'haber', 'saldo_deudor', 'saldo_acreedor'] as $f) {
            $totals[$f] = round($items->sum($f), 2);
        }

        return ['rows' => $items->values()->all(), 'totals' => $totals, 'cuadra' => abs($totals['debe'] - $totals['haber']) < 0.01];
    }

    /** Estado de Resultados por función (simplificado PCGE). */
    public function incomeStatement(int $clientId, string $from, string $to): array
    {
        $b = $this->balancesByCode($clientId, $from, $to);
        $credit = fn (string $prefix) => round($b->filter(fn ($v, $k) => str_starts_with($k, $prefix))->sum(fn ($v) => $v['credit'] - $v['debit']), 2);
        $debit = fn (string $prefix) => round($b->filter(fn ($v, $k) => str_starts_with($k, $prefix))->sum(fn ($v) => $v['debit'] - $v['credit']), 2);

        $sales = $credit('70');
        $otherIncome = round($credit('75') + $credit('73'), 2);
        $financialIncome = $credit('77');
        // Si no se usa la cuenta 69 (costo de ventas), se toman las compras netas (60) como costo.
        $cost = $debit('69') ?: $debit('60');
        $gross = round($sales - $cost, 2);
        $personnel = $debit('62');
        $services = $debit('63');
        $taxes = $debit('64');
        $otherExpenses = round($debit('65') + $debit('68'), 2);
        $financialExpenses = $debit('67');
        $operating = round($gross + $otherIncome - $personnel - $services - $taxes - $otherExpenses, 2);
        $beforeTax = round($operating + $financialIncome - $financialExpenses, 2);
        $incomeTax = $debit('88');
        $net = round($beforeTax - $incomeTax, 2);

        return [
            'lines' => [
                ['concepto' => 'Ventas netas', 'importe' => $sales, 'tipo' => 'ingreso'],
                ['concepto' => 'Costo de ventas', 'importe' => -$cost, 'tipo' => 'gasto'],
                ['concepto' => 'Utilidad bruta', 'importe' => $gross, 'tipo' => 'subtotal'],
                ['concepto' => 'Otros ingresos de gestión', 'importe' => $otherIncome, 'tipo' => 'ingreso'],
                ['concepto' => 'Gastos de personal', 'importe' => -$personnel, 'tipo' => 'gasto'],
                ['concepto' => 'Servicios prestados por terceros', 'importe' => -$services, 'tipo' => 'gasto'],
                ['concepto' => 'Tributos', 'importe' => -$taxes, 'tipo' => 'gasto'],
                ['concepto' => 'Otros gastos de gestión y provisiones', 'importe' => -$otherExpenses, 'tipo' => 'gasto'],
                ['concepto' => 'Utilidad operativa', 'importe' => $operating, 'tipo' => 'subtotal'],
                ['concepto' => 'Ingresos financieros', 'importe' => $financialIncome, 'tipo' => 'ingreso'],
                ['concepto' => 'Gastos financieros', 'importe' => -$financialExpenses, 'tipo' => 'gasto'],
                ['concepto' => 'Resultado antes de impuesto a la renta', 'importe' => $beforeTax, 'tipo' => 'subtotal'],
                ['concepto' => 'Impuesto a la renta', 'importe' => -$incomeTax, 'tipo' => 'gasto'],
                ['concepto' => 'Resultado del ejercicio', 'importe' => $net, 'tipo' => 'total'],
            ],
            'net_income' => $net,
            'sales' => $sales,
        ];
    }

    /** Estado de Situación Financiera al cierre del rango. */
    public function financialPosition(int $clientId, string $to): array
    {
        $b = $this->balancesByCode($clientId, null, $to);
        $year = (int) substr($to, 0, 4);
        $resultYear = $this->incomeStatement($clientId, "{$year}-01-01", $to)['net_income'];

        $sum = function (array $prefixes, bool $debitNature) use ($b) {
            return round($b->filter(function ($v, $k) use ($prefixes) {
                foreach ($prefixes as $p) {
                    if (str_starts_with($k, $p)) {
                        return true;
                    }
                }

                return false;
            })->sum(fn ($v) => $debitNature ? $v['debit'] - $v['credit'] : $v['credit'] - $v['debit']), 2);
        };

        $assets = [
            ['concepto' => 'Efectivo y equivalentes de efectivo', 'importe' => $sum(['10'], true)],
            ['concepto' => 'Cuentas por cobrar comerciales', 'importe' => $sum(['12', '13'], true)],
            ['concepto' => 'Otras cuentas por cobrar', 'importe' => $sum(['14', '16', '17', '18', '19'], true)],
            ['concepto' => 'Inventarios', 'importe' => $sum(['20', '21', '22', '23', '24', '25', '26', '28', '29'], true)],
            ['concepto' => 'Propiedades, planta y equipo (neto)', 'importe' => $sum(['33', '39'], true)],
            ['concepto' => 'Intangibles y otros activos', 'importe' => $sum(['30', '31', '32', '34', '35', '36', '37', '38'], true)],
        ];
        // El IGV (40111) con saldo deudor se presenta como crédito fiscal (activo).
        $igv = $sum(['4011'], false);
        if ($igv < 0) {
            $assets[] = ['concepto' => 'Crédito fiscal IGV', 'importe' => -$igv];
        }

        $liabilities = [
            ['concepto' => 'Tributos por pagar', 'importe' => round($sum(['40'], false) - min($igv, 0), 2)],
            ['concepto' => 'Remuneraciones por pagar', 'importe' => $sum(['41'], false)],
            ['concepto' => 'Cuentas por pagar comerciales', 'importe' => $sum(['42', '43'], false)],
            ['concepto' => 'Obligaciones financieras', 'importe' => $sum(['45'], false)],
            ['concepto' => 'Otras cuentas por pagar', 'importe' => $sum(['44', '46', '47', '48', '49'], false)],
        ];

        $equity = [
            ['concepto' => 'Capital', 'importe' => $sum(['50', '51', '52'], false)],
            ['concepto' => 'Reservas', 'importe' => $sum(['56', '57', '58'], false)],
            ['concepto' => 'Resultados acumulados', 'importe' => $sum(['59'], false)],
            ['concepto' => 'Resultado del ejercicio', 'importe' => $resultYear],
        ];

        $totalAssets = round(array_sum(array_column($assets, 'importe')), 2);
        $totalLiabilities = round(array_sum(array_column($liabilities, 'importe')), 2);
        $totalEquity = round(array_sum(array_column($equity, 'importe')), 2);

        return [
            'activo' => $assets,
            'pasivo' => $liabilities,
            'patrimonio' => $equity,
            'total_activo' => $totalAssets,
            'total_pasivo' => $totalLiabilities,
            'total_patrimonio' => $totalEquity,
            'total_pasivo_patrimonio' => round($totalLiabilities + $totalEquity, 2),
            'cuadra' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.05,
        ];
    }

    /** Resumen tributario mensual: IGV por pagar y pago a cuenta de renta estimado. */
    public function taxEstimate(int $clientId, int $year, int $month): array
    {
        [$from, $to] = self::range($year, $month);
        $sales = $this->salesOrPurchases($clientId, 'venta', $from, $to)['totals'];
        $purchases = $this->salesOrPurchases($clientId, 'compra', $from, $to)['totals'];
        $igvDebit = $sales['igv'] ?? 0;
        $igvCredit = $purchases['igv'] ?? 0;
        $igvPayable = round($igvDebit - $igvCredit, 2);
        $netSales = round(($sales['base_imponible'] ?? 0) + ($sales['exonerado'] ?? 0) + ($sales['inafecto'] ?? 0), 2);
        $rentRate = 0.015;

        return [
            'periodo' => sprintf('%04d-%02d', $year, $month),
            'ventas_netas' => $netSales,
            'igv_debito' => $igvDebit,
            'igv_credito' => $igvCredit,
            'igv_por_pagar' => max($igvPayable, 0),
            'saldo_a_favor' => $igvPayable < 0 ? -$igvPayable : 0,
            'renta_tasa' => $rentRate,
            'renta_pago_a_cuenta' => round($netSales * $rentRate, 2),
            'total_a_pagar' => round(max($igvPayable, 0) + $netSales * $rentRate, 2),
        ];
    }

    /** Cuentas por cobrar / pagar pendientes. */
    public function openItems(int $clientId, string $operation): array
    {
        return Voucher::where('client_id', $clientId)
            ->where('operation', $operation)
            ->where('payment_status', '!=', 'pagado')
            ->where('document_type', '!=', '07')
            ->where('status', '!=', 'anulado')
            ->orderBy('due_date')
            ->get()
            ->map(fn (Voucher $v) => [
                'id' => $v->id,
                'comprobante' => $v->full_number,
                'contraparte' => $v->counterpartName(),
                'emision' => $v->issue_date->format('Y-m-d'),
                'vencimiento' => $v->due_date?->format('Y-m-d'),
                'dias_vencido' => $v->due_date && $v->due_date->isPast() ? (int) $v->due_date->diffInDays(now()) : 0,
                'moneda' => $v->currency,
                'total' => (float) $v->total,
                'pagado' => (float) $v->amount_paid,
                'saldo' => round((float) $v->total - (float) $v->amount_paid, 2),
            ])->all();
    }

    private function balancesQuery(int $clientId, ?string $from, string $to)
    {
        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('je.client_id', $clientId)
            ->where('je.status', 'registrado')
            ->when($from, fn ($q) => $q->where('je.date', '>=', $from))
            ->where('je.date', '<=', $to)
            ->groupBy('l.account_id', 'a.code', 'a.name', 'a.nature')
            ->select('l.account_id', 'a.code', 'a.name', 'a.nature',
                DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit'));
    }

    /** @return Collection<string, array{debit: float, credit: float}> */
    private function balancesByCode(int $clientId, ?string $from, string $to): Collection
    {
        return $this->balancesQuery($clientId, $from, $to)->get()
            ->mapWithKeys(fn ($r) => [$r->code => ['debit' => (float) $r->debit, 'credit' => (float) $r->credit]]);
    }
}
