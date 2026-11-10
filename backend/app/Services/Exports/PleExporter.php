<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\Voucher;
use App\Services\Accounting\LedgerService;

/**
 * Genera archivos TXT del Programa de Libros Electrónicos (PLE) de SUNAT:
 *  - 080100: Registro de Compras
 *  - 140100: Registro de Ventas e Ingresos
 * Formato: campos separados por "|", una línea por comprobante.
 */
class PleExporter
{
    /** @return array{filename: string, content: string} */
    public function export(Client $client, string $operation, int $year, int $month): array
    {
        [$from, $to] = LedgerService::range($year, $month);
        $vouchers = Voucher::where('client_id', $client->id)
            ->where('operation', $operation)
            ->whereBetween('issue_date', [$from, $to])
            ->orderBy('issue_date')->orderBy('series')->orderBy('number')
            ->get();

        $period = sprintf('%04d%02d00', $year, $month);
        $book = $operation === 'compra' ? '080100' : '140100';
        $lines = [];

        foreach ($vouchers->values() as $i => $v) {
            $lines[] = implode('|', $operation === 'compra'
                ? $this->purchaseLine($v, $period, $i + 1)
                : $this->saleLine($v, $period, $i + 1)).'|';
        }

        $hasData = $vouchers->isNotEmpty() ? '1' : '0';
        // LE + RUC + AAAAMM00 + libro + 00 (oportunidad) + 1 (operaciones) + indicador contenido + 1 (moneda PEN) + 1
        $filename = "LE{$client->ruc}{$period}{$book}001{$hasData}11.txt";

        return ['filename' => $filename, 'content' => implode("\r\n", $lines).($lines ? "\r\n" : '')];
    }

    private function purchaseLine(Voucher $v, string $period, int $seq): array
    {
        $m = fn ($f) => number_format($v->signedPen($f), 2, '.', '');

        return [
            $period,                                   // 1 Periodo
            'C'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT), // 2 CUO
            'M'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT), // 3 Correlativo
            $v->issue_date->format('d/m/Y'),           // 4 Fecha emisión
            $v->due_date?->format('d/m/Y') ?? '01/01/0001', // 5 Fecha vencimiento
            $v->document_type,                         // 6 Tipo comprobante
            $v->series,                                // 7 Serie
            '',                                        // 8 Año DUA
            ltrim($v->number, '0'),                    // 9 Número
            '',                                        // 10 Importe total operaciones diarias sin crédito fiscal
            '6',                                       // 11 Tipo doc proveedor
            $v->issuer_ruc,                            // 12 Número doc proveedor
            mb_substr($v->issuer_name, 0, 100),        // 13 Razón social
            $m('taxable_amount'),                      // 14 Base imponible gravadas destinadas a op. gravadas
            $m('igv'),                                 // 15 IGV
            '0.00', '0.00',                            // 16-17 Base/IGV op. gravadas y no gravadas
            '0.00', '0.00',                            // 18-19 Base/IGV op. no gravadas
            number_format($v->signedPen('exempt_amount') + $v->signedPen('unaffected_amount'), 2, '.', ''), // 20 No gravadas
            $m('isc'),                                 // 21 ISC
            '0.00',                                    // 22 ICBPER
            $m('other_charges'),                       // 23 Otros tributos
            $m('total'),                               // 24 Importe total
            $v->currency,                              // 25 Moneda
            number_format((float) $v->exchange_rate, 3, '.', ''), // 26 Tipo de cambio
            '', '', '', '', '',                        // 27-31 Referencia NC/ND
            '', '',                                    // 32-33 Detracción
            '',                                        // 34 Retención
            '', '', '', '', '', '', '', '',            // 35-42 Clasificación / indicadores
            '1',                                       // 43 Estado (1 = registrado en el periodo)
        ];
    }

    private function saleLine(Voucher $v, string $period, int $seq): array
    {
        $m = fn ($f) => number_format($v->signedPen($f), 2, '.', '');

        return [
            $period,                                   // 1 Periodo
            'V'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT), // 2 CUO
            'M'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT), // 3 Correlativo
            $v->issue_date->format('d/m/Y'),           // 4 Fecha emisión
            $v->due_date?->format('d/m/Y') ?? '01/01/0001', // 5 Fecha vencimiento
            $v->document_type,                         // 6 Tipo comprobante
            $v->series,                                // 7 Serie
            ltrim($v->number, '0'),                    // 8 Número
            '',                                        // 9 Ticket / final de rango
            $v->customer_doc_type ?: '0',              // 10 Tipo doc cliente
            $v->customer_doc ?: '0',                   // 11 Número doc cliente
            mb_substr($v->customer_name ?: 'CLIENTES VARIOS', 0, 100), // 12 Razón social
            '0.00',                                    // 13 Valor facturado exportación
            $m('taxable_amount'),                      // 14 Base imponible
            '0.00',                                    // 15 Descuento base
            $m('igv'),                                 // 16 IGV
            '0.00',                                    // 17 Descuento IGV
            $m('exempt_amount'),                       // 18 Exonerado
            $m('unaffected_amount'),                   // 19 Inafecto
            $m('isc'),                                 // 20 ISC
            '0.00', '0.00',                            // 21-22 Arroz pilado
            '0.00',                                    // 23 ICBPER
            $m('other_charges'),                       // 24 Otros tributos
            $m('total'),                               // 25 Importe total
            $v->currency,                              // 26 Moneda
            number_format((float) $v->exchange_rate, 3, '.', ''), // 27 Tipo de cambio
            '', '', '', '',                            // 28-31 Referencia NC/ND
            '',                                        // 32 Contrato
            '', '',                                    // 33-34 Indicadores
            '1',                                       // 35 Estado
        ];
    }
}
