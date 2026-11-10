<?php

namespace App\Services\Accounting;

use InvalidArgumentException;

/**
 * Convierte libros y estados financieros en tablas exportables (PDF / Excel).
 */
class BookTables
{
    public const BOOKS = [
        'registro_compras' => 'Registro de Compras',
        'registro_ventas' => 'Registro de Ventas e Ingresos',
        'libro_diario' => 'Libro Diario',
        'libro_mayor' => 'Libro Mayor',
        'balance_comprobacion' => 'Balance de Comprobación',
        'situacion_financiera' => 'Estado de Situación Financiera',
        'resultados' => 'Estado de Resultados',
        'eeff_anual' => 'Estados Financieros Anuales',
    ];

    public function __construct(private readonly LedgerService $ledger) {}

    /** @return array{title: string, tables: array, data: array} */
    public function build(string $type, int $clientId, int $year, ?int $month): array
    {
        [$from, $to] = LedgerService::range($year, $month);

        return match ($type) {
            'registro_compras', 'registro_ventas' => $this->register($type, $clientId, $from, $to),
            'libro_diario' => $this->journal($clientId, $from, $to),
            'libro_mayor' => $this->ledgerBook($clientId, $from, $to),
            'balance_comprobacion' => $this->trial($clientId, $from, $to),
            'situacion_financiera' => $this->position($clientId, $to),
            'resultados' => $this->income($clientId, $from, $to),
            'eeff_anual' => $this->annual($clientId, $year),
            default => throw new InvalidArgumentException("Tipo de libro no soportado: {$type}"),
        };
    }

    private function register(string $type, int $clientId, string $from, string $to): array
    {
        $isPurchase = $type === 'registro_compras';
        $data = $this->ledger->salesOrPurchases($clientId, $isPurchase ? 'compra' : 'venta', $from, $to);

        return ['title' => self::BOOKS[$type], 'data' => $data, 'tables' => [[
            'title' => self::BOOKS[$type],
            'columns' => [
                ['key' => 'correlativo', 'label' => 'N°'],
                ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'tipo', 'label' => 'TD'],
                ['key' => 'serie', 'label' => 'Serie'],
                ['key' => 'numero', 'label' => 'Número'],
                ['key' => 'doc_contraparte', 'label' => $isPurchase ? 'RUC proveedor' : 'Doc. cliente'],
                ['key' => 'contraparte', 'label' => $isPurchase ? 'Proveedor' : 'Cliente'],
                ['key' => 'base_imponible', 'label' => 'Base imp.', 'type' => 'money'],
                ['key' => 'exonerado', 'label' => 'Exonerado', 'type' => 'money'],
                ['key' => 'inafecto', 'label' => 'Inafecto', 'type' => 'money'],
                ['key' => 'igv', 'label' => 'IGV', 'type' => 'money'],
                ['key' => 'otros', 'label' => 'Otros', 'type' => 'money'],
                ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
            ],
            'rows' => $data['rows'],
            'totals' => $data['totals'],
        ]]];
    }

    private function journal(int $clientId, string $from, string $to): array
    {
        $data = $this->ledger->journal($clientId, $from, $to);
        $rows = [];
        foreach ($data['entries'] as $e) {
            foreach ($e['lineas'] as $i => $l) {
                $rows[] = [
                    'asiento' => $i === 0 ? $e['numero'] : '',
                    'fecha' => $i === 0 ? $e['fecha'] : '',
                    'glosa' => $i === 0 ? $e['glosa'] : '   '.($l['glosa'] ?? ''),
                    'cuenta' => $l['cuenta'],
                    'denominacion' => $l['denominacion'],
                    'debe' => $l['debe'] ?: null,
                    'haber' => $l['haber'] ?: null,
                ];
            }
        }

        return ['title' => self::BOOKS['libro_diario'], 'data' => $data, 'tables' => [[
            'title' => self::BOOKS['libro_diario'],
            'columns' => [
                ['key' => 'asiento', 'label' => 'Asiento'], ['key' => 'fecha', 'label' => 'Fecha'],
                ['key' => 'glosa', 'label' => 'Glosa'], ['key' => 'cuenta', 'label' => 'Cuenta'],
                ['key' => 'denominacion', 'label' => 'Denominación'],
                ['key' => 'debe', 'label' => 'Debe', 'type' => 'money'], ['key' => 'haber', 'label' => 'Haber', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totals' => $data['totals'],
        ]]];
    }

    private function ledgerBook(int $clientId, string $from, string $to): array
    {
        $data = $this->ledger->ledger($clientId, $from, $to);
        $tables = array_map(fn ($a) => [
            'title' => "{$a['cuenta']} {$a['denominacion']} (saldo inicial: ".number_format($a['saldo_inicial'], 2).')',
            'columns' => [
                ['key' => 'fecha', 'label' => 'Fecha'], ['key' => 'asiento', 'label' => 'Asiento'],
                ['key' => 'glosa', 'label' => 'Glosa'],
                ['key' => 'debe', 'label' => 'Debe', 'type' => 'money'], ['key' => 'haber', 'label' => 'Haber', 'type' => 'money'],
                ['key' => 'saldo', 'label' => 'Saldo', 'type' => 'money'],
            ],
            'rows' => $a['movimientos'],
            'totals' => ['debe' => $a['total_debe'], 'haber' => $a['total_haber'], 'saldo' => $a['saldo_final']],
        ], $data['accounts']);

        return ['title' => self::BOOKS['libro_mayor'], 'data' => $data, 'tables' => $tables ?: [[
            'title' => 'Libro Mayor', 'columns' => [['key' => 'x', 'label' => 'Sin movimientos']], 'rows' => [],
        ]]];
    }

    private function trial(int $clientId, string $from, string $to): array
    {
        $data = $this->ledger->trialBalance($clientId, $from, $to);

        return ['title' => self::BOOKS['balance_comprobacion'], 'data' => $data, 'tables' => [[
            'title' => self::BOOKS['balance_comprobacion'],
            'columns' => [
                ['key' => 'cuenta', 'label' => 'Cuenta'], ['key' => 'denominacion', 'label' => 'Denominación'],
                ['key' => 'debe', 'label' => 'Debe', 'type' => 'money'], ['key' => 'haber', 'label' => 'Haber', 'type' => 'money'],
                ['key' => 'saldo_deudor', 'label' => 'Saldo deudor', 'type' => 'money'],
                ['key' => 'saldo_acreedor', 'label' => 'Saldo acreedor', 'type' => 'money'],
            ],
            'rows' => $data['rows'],
            'totals' => $data['totals'],
        ]]];
    }

    private function position(int $clientId, string $to): array
    {
        $d = $this->ledger->financialPosition($clientId, $to);
        $cols = [['key' => 'concepto', 'label' => 'Concepto'], ['key' => 'importe', 'label' => 'S/', 'type' => 'money']];

        return ['title' => self::BOOKS['situacion_financiera'], 'data' => $d, 'tables' => [
            ['title' => 'ACTIVO', 'columns' => $cols, 'rows' => $d['activo'], 'totals' => ['importe' => $d['total_activo']]],
            ['title' => 'PASIVO', 'columns' => $cols, 'rows' => $d['pasivo'], 'totals' => ['importe' => $d['total_pasivo']]],
            ['title' => 'PATRIMONIO', 'columns' => $cols, 'rows' => $d['patrimonio'], 'totals' => ['importe' => $d['total_patrimonio']]],
            ['title' => 'TOTAL PASIVO Y PATRIMONIO', 'columns' => $cols, 'rows' => [
                ['concepto' => 'Total pasivo y patrimonio', 'importe' => $d['total_pasivo_patrimonio'], '_bold' => true],
            ]],
        ]];
    }

    private function income(int $clientId, string $from, string $to): array
    {
        $d = $this->ledger->incomeStatement($clientId, $from, $to);

        return ['title' => self::BOOKS['resultados'], 'data' => $d, 'tables' => [[
            'title' => self::BOOKS['resultados'],
            'columns' => [['key' => 'concepto', 'label' => 'Concepto'], ['key' => 'importe', 'label' => 'S/', 'type' => 'money']],
            'rows' => array_map(fn ($l) => $l + ['_bold' => in_array($l['tipo'], ['subtotal', 'total'], true)], $d['lines']),
        ]]];
    }

    private function annual(int $clientId, int $year): array
    {
        $position = $this->position($clientId, "{$year}-12-31");
        $income = $this->income($clientId, "{$year}-01-01", "{$year}-12-31");
        $trial = $this->trial($clientId, "{$year}-01-01", "{$year}-12-31");

        return [
            'title' => self::BOOKS['eeff_anual'],
            'data' => ['situacion' => $position['data'], 'resultados' => $income['data']],
            'tables' => array_merge($position['tables'], $income['tables'], $trial['tables']),
        ];
    }
}
