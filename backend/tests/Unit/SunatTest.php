<?php

namespace Tests\Unit;

use App\Services\Sunat\RucValidator;
use App\Services\Sunat\UblInvoiceParser;
use Database\Seeders\Support\SampleUbl;
use PHPUnit\Framework\TestCase;

class SunatTest extends TestCase
{
    public function test_ruc_check_digit_validation(): void
    {
        $ruc = RucValidator::withCheckDigit('2060123456');
        $this->assertTrue(RucValidator::isValid($ruc));
        $wrong = substr($ruc, 0, 10).(((int) $ruc[10] + 1) % 10);
        $this->assertFalse(RucValidator::isValid($wrong));
        $this->assertFalse(RucValidator::isValid('123'));
    }

    public function test_parses_sunat_ubl_invoice(): void
    {
        $xml = SampleUbl::invoice('F001', 45, '2026-10-05', '20601234561', 'PROVEEDOR SAC', '20100123456', 'CLIENTE SAC',
            [['Producto A', 2, 100.00], ['Servicio B', 1, 50.00]]);

        $data = (new UblInvoiceParser())->parse($xml);

        $this->assertSame('01', $data['document_type']);
        $this->assertSame('F001', $data['series']);
        $this->assertSame('00000045', $data['number']);
        $this->assertSame('2026-10-05', $data['issue_date']);
        $this->assertSame('20601234561', $data['issuer_ruc']);
        $this->assertSame('20100123456', $data['customer_doc']);
        $this->assertEquals(250.00, $data['taxable_amount']);
        $this->assertEquals(45.00, $data['igv']);
        $this->assertEquals(295.00, $data['total']);
        $this->assertCount(2, $data['items']);
    }

    public function test_parses_credit_note(): void
    {
        $xml = SampleUbl::invoice('FC01', 3, '2026-10-06', '20601234561', 'PROVEEDOR SAC', '20100123456', 'CLIENTE SAC',
            [['Devolución', 1, 100.00]], '07', 'PEN', 'F001-45');
        $data = (new UblInvoiceParser())->parse($xml);

        $this->assertSame('07', $data['document_type']);
        $this->assertSame('F001-45', $data['reference_doc']);
        $this->assertEquals(118.00, $data['total']);
    }

    public function test_rejects_invalid_xml(): void
    {
        $this->expectException(\RuntimeException::class);
        (new UblInvoiceParser())->parse('<foo>bar</foo>');
    }
}
