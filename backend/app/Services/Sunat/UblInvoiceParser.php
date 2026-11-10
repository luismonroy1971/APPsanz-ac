<?php

namespace App\Services\Sunat;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Parser de comprobantes electrónicos SUNAT en formato UBL 2.1
 * (Invoice = factura/boleta, CreditNote = nota de crédito, DebitNote = nota de débito).
 */
class UblInvoiceParser
{
    private const NS = [
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
    ];

    /** Códigos de tributo SUNAT (catálogo 05). */
    private const TAX_IGV = '1000';
    private const TAX_IVAP = '1016';
    private const TAX_ISC = '2000';
    private const TAX_EXPORT = '9995';
    private const TAX_FREE = '9996';
    private const TAX_EXEMPT = '9997';
    private const TAX_UNAFFECTED = '9998';
    private const TAX_OTHER = '9999';

    private DOMXPath $xp;

    public function parse(string $xml): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $dom->documentElement) {
            throw new RuntimeException('El archivo XML no es válido.');
        }

        $root = $dom->documentElement;
        $rootName = $root->localName;
        if (! in_array($rootName, ['Invoice', 'CreditNote', 'DebitNote'], true)) {
            throw new RuntimeException("El XML no corresponde a un comprobante UBL soportado ({$rootName}).");
        }

        $this->xp = new DOMXPath($dom);
        foreach (self::NS as $prefix => $uri) {
            $this->xp->registerNamespace($prefix, $uri);
        }

        $id = $this->text('/*/cbc:ID');
        if (! $id || ! str_contains($id, '-')) {
            throw new RuntimeException('No se encontró la serie-número del comprobante (cbc:ID).');
        }
        [$series, $number] = explode('-', $id, 2);

        $documentType = match ($rootName) {
            'CreditNote' => '07',
            'DebitNote' => '08',
            default => $this->text('/*/cbc:InvoiceTypeCode') ?: '01',
        };

        $totals = $this->taxTotals();
        $monetaryPath = $rootName === 'DebitNote' ? '/*/cac:RequestedMonetaryTotal' : '/*/cac:LegalMonetaryTotal';
        $payable = (float) $this->text($monetaryPath.'/cbc:PayableAmount');
        $charges = (float) $this->text($monetaryPath.'/cbc:ChargeTotalAmount');

        // Si el XML no detalla la base gravada, la deducimos del total.
        $taxable = $totals['taxable'];
        if ($taxable == 0.0 && $totals['igv'] > 0) {
            $taxable = round($payable - $totals['igv'] - $totals['exempt'] - $totals['unaffected'] - $charges, 2);
        }

        return [
            'ubl_version' => $this->text('/*/cbc:UBLVersionID'),
            'document_type' => str_pad($documentType, 2, '0', STR_PAD_LEFT),
            'series' => strtoupper(trim($series)),
            'number' => str_pad(ltrim(trim($number), '0') ?: '0', 8, '0', STR_PAD_LEFT),
            'issue_date' => $this->text('/*/cbc:IssueDate'),
            'due_date' => $this->text('/*/cbc:DueDate')
                ?: $this->text('/*/cac:PaymentTerms/cbc:PaymentDueDate'),
            'currency' => $this->text('/*/cbc:DocumentCurrencyCode') ?: 'PEN',
            'issuer_ruc' => $this->text('/*/cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID')
                ?: $this->text('/*/cac:AccountingSupplierParty/cbc:CustomerAssignedAccountID'),
            'issuer_name' => $this->text('/*/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName')
                ?: $this->text('/*/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name'),
            'customer_doc_type' => $this->attr('/*/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID', 'schemeID') ?: '6',
            'customer_doc' => $this->text('/*/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID')
                ?: $this->text('/*/cac:AccountingCustomerParty/cbc:CustomerAssignedAccountID'),
            'customer_name' => $this->text('/*/cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName'),
            'taxable_amount' => round($taxable, 2),
            'exempt_amount' => round($totals['exempt'], 2),
            'unaffected_amount' => round($totals['unaffected'], 2),
            'igv' => round($totals['igv'], 2),
            'isc' => round($totals['isc'], 2),
            'other_charges' => round($charges + $totals['other'], 2),
            'total' => round($payable, 2),
            'reference_doc' => $this->text('/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID')
                ?: $this->text('/*/cac:DiscrepancyResponse/cbc:ReferenceID'),
            'items' => $this->items($rootName),
        ];
    }

    private function taxTotals(): array
    {
        $out = ['taxable' => 0.0, 'igv' => 0.0, 'isc' => 0.0, 'exempt' => 0.0, 'unaffected' => 0.0, 'other' => 0.0];

        foreach ($this->xp->query('/*/cac:TaxTotal/cac:TaxSubtotal') as $sub) {
            $code = $this->text('cac:TaxCategory/cac:TaxScheme/cbc:ID', $sub);
            $base = (float) $this->text('cbc:TaxableAmount', $sub);
            $tax = (float) $this->text('cbc:TaxAmount', $sub);

            switch ($code) {
                case self::TAX_IGV:
                case self::TAX_IVAP:
                    $out['taxable'] += $base;
                    $out['igv'] += $tax;
                    break;
                case self::TAX_ISC:
                    $out['isc'] += $tax;
                    break;
                case self::TAX_EXEMPT:
                    $out['exempt'] += $base;
                    break;
                case self::TAX_UNAFFECTED:
                case self::TAX_EXPORT:
                    $out['unaffected'] += $base;
                    break;
                case self::TAX_FREE:
                    break; // operaciones gratuitas: no suman al total
                case self::TAX_OTHER:
                default:
                    $out['other'] += $tax;
            }
        }

        return $out;
    }

    private function items(string $rootName): array
    {
        $lineTag = match ($rootName) {
            'CreditNote' => 'cac:CreditNoteLine',
            'DebitNote' => 'cac:DebitNoteLine',
            default => 'cac:InvoiceLine',
        };
        $qtyTag = match ($rootName) {
            'CreditNote' => 'cbc:CreditedQuantity',
            'DebitNote' => 'cbc:DebitedQuantity',
            default => 'cbc:InvoicedQuantity',
        };

        $items = [];
        foreach ($this->xp->query('/*/'.$lineTag) as $line) {
            $subtotal = (float) $this->text('cbc:LineExtensionAmount', $line);
            $igv = (float) $this->text('cac:TaxTotal/cbc:TaxAmount', $line);
            $items[] = [
                'code' => $this->text('cac:Item/cac:SellersItemIdentification/cbc:ID', $line),
                'description' => trim((string) $this->text('cac:Item/cbc:Description', $line)) ?: 'Ítem',
                'unit' => $this->attr($qtyTag, 'unitCode', $line),
                'quantity' => (float) ($this->text($qtyTag, $line) ?: 1),
                'unit_price' => (float) $this->text('cac:Price/cbc:PriceAmount', $line),
                'subtotal' => round($subtotal, 2),
                'igv' => round($igv, 2),
                'total' => round($subtotal + $igv, 2),
            ];
        }

        return $items;
    }

    private function text(string $path, ?DOMElement $ctx = null): ?string
    {
        $node = $this->xp->query($path, $ctx)->item(0);

        return $node ? trim($node->textContent) : null;
    }

    private function attr(string $path, string $attr, ?DOMElement $ctx = null): ?string
    {
        $node = $this->xp->query($path, $ctx)->item(0);

        return $node instanceof DOMElement && $node->hasAttribute($attr) ? $node->getAttribute($attr) : null;
    }
}
