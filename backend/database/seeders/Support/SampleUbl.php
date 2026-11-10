<?php

namespace Database\Seeders\Support;

/**
 * Genera XML UBL 2.1 de ejemplo con la estructura SUNAT (factura / boleta / NC).
 * Útil para datos de demostración y pruebas automatizadas.
 */
class SampleUbl
{
    /**
     * @param  array<int, array{0: string, 1: float, 2: float}>  $lines  [descripción, cantidad, valor unitario sin IGV]
     */
    public static function invoice(
        string $series,
        int $number,
        string $date,
        string $issuerRuc,
        string $issuerName,
        string $customerRuc,
        string $customerName,
        array $lines,
        string $type = '01',
        string $currency = 'PEN',
        ?string $reference = null,
    ): string {
        $root = $type === '07' ? 'CreditNote' : 'Invoice';
        $ns = $type === '07' ? 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2' : 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
        $lineTag = $type === '07' ? 'CreditNoteLine' : 'InvoiceLine';
        $qtyTag = $type === '07' ? 'CreditedQuantity' : 'InvoicedQuantity';
        $customerScheme = strlen($customerRuc) === 8 ? '1' : '6';

        $base = 0;
        $linesXml = '';
        foreach ($lines as $i => [$desc, $qty, $price]) {
            $sub = round($qty * $price, 2);
            $igv = round($sub * 0.18, 2);
            $base += $sub;
            $n = $i + 1;
            $priceInc = number_format($price * 1.18, 2, '.', '');
            $linesXml .= <<<XML
    <cac:{$lineTag}>
        <cbc:ID>{$n}</cbc:ID>
        <cbc:{$qtyTag} unitCode="NIU">{$qty}</cbc:{$qtyTag}>
        <cbc:LineExtensionAmount currencyID="{$currency}">{$sub}</cbc:LineExtensionAmount>
        <cac:PricingReference><cac:AlternativeConditionPrice><cbc:PriceAmount currencyID="{$currency}">{$priceInc}</cbc:PriceAmount><cbc:PriceTypeCode>01</cbc:PriceTypeCode></cac:AlternativeConditionPrice></cac:PricingReference>
        <cac:TaxTotal>
            <cbc:TaxAmount currencyID="{$currency}">{$igv}</cbc:TaxAmount>
            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID="{$currency}">{$sub}</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID="{$currency}">{$igv}</cbc:TaxAmount>
                <cac:TaxCategory><cbc:Percent>18</cbc:Percent><cbc:TaxExemptionReasonCode>10</cbc:TaxExemptionReasonCode>
                    <cac:TaxScheme><cbc:ID>1000</cbc:ID><cbc:Name>IGV</cbc:Name><cbc:TaxTypeCode>VAT</cbc:TaxTypeCode></cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        </cac:TaxTotal>
        <cac:Item><cbc:Description><![CDATA[{$desc}]]></cbc:Description><cac:SellersItemIdentification><cbc:ID>P{$n}</cbc:ID></cac:SellersItemIdentification></cac:Item>
        <cac:Price><cbc:PriceAmount currencyID="{$currency}">{$price}</cbc:PriceAmount></cac:Price>
    </cac:{$lineTag}>

XML;
        }

        $base = round($base, 2);
        $igvTotal = round($base * 0.18, 2);
        $total = round($base + $igvTotal, 2);
        $id = sprintf('%s-%d', $series, $number);
        $typeCode = $type === '07' ? '' : "<cbc:InvoiceTypeCode listID=\"0101\">{$type}</cbc:InvoiceTypeCode>";
        $due = $type === '07' ? '' : '<cbc:DueDate>'.date('Y-m-d', strtotime($date.' +30 days')).'</cbc:DueDate>';
        $ref = $reference
            ? "<cac:DiscrepancyResponse><cbc:ReferenceID>{$reference}</cbc:ReferenceID><cbc:ResponseCode>07</cbc:ResponseCode><cbc:Description>Devolución por ítem</cbc:Description></cac:DiscrepancyResponse>"
              ."<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>{$reference}</cbc:ID><cbc:DocumentTypeCode>01</cbc:DocumentTypeCode></cac:InvoiceDocumentReference></cac:BillingReference>"
            : '';
        $issuerNameEsc = htmlspecialchars($issuerName, ENT_XML1);
        $customerNameEsc = htmlspecialchars($customerName, ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<{$root} xmlns="{$ns}"
    xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
    xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">
    <ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>
    <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
    <cbc:CustomizationID>2.0</cbc:CustomizationID>
    <cbc:ID>{$id}</cbc:ID>
    <cbc:IssueDate>{$date}</cbc:IssueDate>
    {$due}
    {$typeCode}
    <cbc:DocumentCurrencyCode>{$currency}</cbc:DocumentCurrencyCode>
    {$ref}
    <cac:AccountingSupplierParty><cac:Party>
        <cac:PartyIdentification><cbc:ID schemeID="6">{$issuerRuc}</cbc:ID></cac:PartyIdentification>
        <cac:PartyName><cbc:Name><![CDATA[{$issuerNameEsc}]]></cbc:Name></cac:PartyName>
        <cac:PartyLegalEntity><cbc:RegistrationName><![CDATA[{$issuerNameEsc}]]></cbc:RegistrationName></cac:PartyLegalEntity>
    </cac:Party></cac:AccountingSupplierParty>
    <cac:AccountingCustomerParty><cac:Party>
        <cac:PartyIdentification><cbc:ID schemeID="{$customerScheme}">{$customerRuc}</cbc:ID></cac:PartyIdentification>
        <cac:PartyLegalEntity><cbc:RegistrationName><![CDATA[{$customerNameEsc}]]></cbc:RegistrationName></cac:PartyLegalEntity>
    </cac:Party></cac:AccountingCustomerParty>
    <cac:TaxTotal>
        <cbc:TaxAmount currencyID="{$currency}">{$igvTotal}</cbc:TaxAmount>
        <cac:TaxSubtotal>
            <cbc:TaxableAmount currencyID="{$currency}">{$base}</cbc:TaxableAmount>
            <cbc:TaxAmount currencyID="{$currency}">{$igvTotal}</cbc:TaxAmount>
            <cac:TaxCategory><cac:TaxScheme><cbc:ID>1000</cbc:ID><cbc:Name>IGV</cbc:Name><cbc:TaxTypeCode>VAT</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory>
        </cac:TaxSubtotal>
    </cac:TaxTotal>
    <cac:LegalMonetaryTotal>
        <cbc:LineExtensionAmount currencyID="{$currency}">{$base}</cbc:LineExtensionAmount>
        <cbc:TaxInclusiveAmount currencyID="{$currency}">{$total}</cbc:TaxInclusiveAmount>
        <cbc:PayableAmount currencyID="{$currency}">{$total}</cbc:PayableAmount>
    </cac:LegalMonetaryTotal>
{$linesXml}</{$root}>
XML;
    }
}
