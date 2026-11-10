<?php

namespace App\Services\Accounting;

use App\Models\Client;
use App\Models\Voucher;
use App\Services\Sunat\RucValidator;

/**
 * Valida los datos de un comprobante antes de registrarlo.
 * Devuelve errores (bloqueantes) y advertencias (informativas).
 */
class VoucherValidator
{
    /** @return array{operation: ?string, errors: array<string>, warnings: array<string>} */
    public function validate(array $data, Client $client, ?int $ignoreVoucherId = null): array
    {
        $errors = [];
        $warnings = [];
        $tolerance = (float) config('accounting.tolerance');

        foreach (['document_type', 'series', 'number', 'issue_date', 'issuer_ruc', 'total'] as $field) {
            if (blank($data[$field] ?? null)) {
                $errors[] = "Falta el dato obligatorio: {$field}.";
            }
        }
        if ($errors) {
            return ['operation' => null, 'errors' => $errors, 'warnings' => $warnings];
        }

        if (! array_key_exists($data['document_type'], Voucher::DOCUMENT_TYPES)) {
            $errors[] = "Tipo de comprobante no soportado ({$data['document_type']}).";
        }

        if (! RucValidator::isValid($data['issuer_ruc'])) {
            $errors[] = "El RUC del emisor ({$data['issuer_ruc']}) no es válido.";
        }

        // Determinar si es compra o venta según el RUC del cliente
        $operation = null;
        if ($data['issuer_ruc'] === $client->ruc) {
            $operation = 'venta';
        } elseif (($data['customer_doc'] ?? null) === $client->ruc) {
            $operation = 'compra';
        } else {
            $errors[] = "El comprobante no corresponde al cliente: ni el emisor ni el adquirente tienen el RUC {$client->ruc}.";
        }

        $ts = strtotime((string) $data['issue_date']);
        if (! $ts) {
            $errors[] = 'La fecha de emisión no es válida.';
        } elseif ($ts > strtotime('tomorrow')) {
            $errors[] = 'La fecha de emisión es futura.';
        }

        $taxable = (float) ($data['taxable_amount'] ?? 0);
        $igv = (float) ($data['igv'] ?? 0);
        $sum = $taxable + (float) ($data['exempt_amount'] ?? 0) + (float) ($data['unaffected_amount'] ?? 0)
            + $igv + (float) ($data['isc'] ?? 0) + (float) ($data['other_charges'] ?? 0);
        $total = (float) $data['total'];

        if ($total <= 0) {
            $errors[] = 'El importe total debe ser mayor a cero.';
        } elseif (abs($sum - $total) > $tolerance) {
            $errors[] = sprintf('Los importes no cuadran: base + IGV + otros = %.2f, total = %.2f.', $sum, $total);
        }

        $expectedIgv = round($taxable * (float) config('accounting.igv_rate'), 2);
        if ($taxable > 0 && abs($expectedIgv - $igv) > $tolerance) {
            $warnings[] = sprintf('El IGV (%.2f) difiere del 18%% de la base imponible (%.2f). Verificar tasa o IVAP.', $igv, $expectedIgv);
        }

        if ($data['document_type'] === '01' && $operation === 'compra' && ($data['customer_doc_type'] ?? '6') !== '6') {
            $warnings[] = 'Factura de compra cuyo adquirente no se identifica con RUC.';
        }

        if (in_array($data['document_type'], ['07', '08'], true) && blank($data['reference_doc'] ?? null)) {
            $warnings[] = 'La nota no indica el comprobante de referencia.';
        }

        if (($data['currency'] ?? 'PEN') !== 'PEN' && (float) ($data['exchange_rate'] ?? 0) <= 1) {
            $warnings[] = 'Comprobante en moneda extranjera: registrar el tipo de cambio SUNAT de la fecha de emisión.';
        }

        if ($operation && ! $errors) {
            $duplicate = Voucher::query()
                ->where('client_id', $client->id)
                ->where('operation', $operation)
                ->where('issuer_ruc', $data['issuer_ruc'])
                ->where('document_type', $data['document_type'])
                ->where('series', strtoupper($data['series']))
                ->where('number', str_pad(ltrim((string) $data['number'], '0') ?: '0', 8, '0', STR_PAD_LEFT))
                ->when($ignoreVoucherId, fn ($q) => $q->where('id', '!=', $ignoreVoucherId))
                ->exists();
            if ($duplicate) {
                $errors[] = 'El comprobante ya fue registrado anteriormente (duplicado).';
            }
        }

        return ['operation' => $operation, 'errors' => $errors, 'warnings' => $warnings];
    }
}
