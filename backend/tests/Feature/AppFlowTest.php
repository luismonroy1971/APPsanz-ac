<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Sunat\RucValidator;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\Support\SampleUbl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppFlowTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Client $other;
    private User $clientUser;
    private User $analyst;
    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([RoleSeeder::class, AccountSeeder::class]);

        $this->client = Client::create(['ruc' => RucValidator::withCheckDigit('2060123456'), 'business_name' => 'CLIENTE UNO SAC', 'tax_regime' => 'general']);
        $this->other = Client::create(['ruc' => RucValidator::withCheckDigit('2060765432'), 'business_name' => 'CLIENTE DOS SAC', 'tax_regime' => 'general']);

        $this->clientUser = User::create(['name' => 'Cliente', 'email' => 'c@x.pe', 'type' => 'externo', 'client_id' => $this->client->id, 'password' => 'secret123']);
        $this->clientUser->assignRole('cliente');
        $this->clientUser->syncPermissions(['documentos.subir', 'consultas.usar', 'informes.ver']);

        $this->analyst = User::create(['name' => 'Analista', 'email' => 'a@sanz.pe', 'type' => 'interno', 'password' => 'secret123']);
        $this->analyst->assignRole('analista');
        $this->client->team()->attach($this->analyst->id, ['role' => 'analista']);

        $this->supervisor = User::create(['name' => 'Supervisor', 'email' => 's@sanz.pe', 'type' => 'interno', 'password' => 'secret123']);
        $this->supervisor->assignRole('supervisor');
    }

    private function purchaseXml(int $number = 77): string
    {
        return SampleUbl::invoice('F010', $number, now()->subDay()->toDateString(), RucValidator::withCheckDigit('2010012345'),
            'PROVEEDOR DEMO SAC', $this->client->ruc, $this->client->business_name, [['Mercadería', 10, 100.00]]);
    }

    public function test_login_logs_access_and_rejects_wrong_portal(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'c@x.pe', 'password' => 'secret123', 'portal' => 'interno'])
            ->assertStatus(422);

        $res = $this->postJson('/api/v1/auth/login', ['email' => 'c@x.pe', 'password' => 'secret123', 'portal' => 'externo'])
            ->assertOk()->assertJsonPath('user.role', 'cliente');

        $this->assertNotEmpty($res->json('token'));
        $this->assertDatabaseHas('access_logs', ['user_id' => $this->clientUser->id, 'event' => 'login', 'client_id' => $this->client->id]);
    }

    public function test_client_upload_of_sunat_xml_is_processed_into_voucher_and_journal_entry(): void
    {
        $file = UploadedFile::fake()->createWithContent('factura.xml', $this->purchaseXml());

        $this->actingAs($this->clientUser)
            ->postJson('/api/v1/documents', ['files' => [$file], 'category' => 'comprobante'])
            ->assertCreated();

        $doc = Document::first();
        $this->assertSame('procesado', $doc->status);
        $this->assertSame('xml_ubl', $doc->processing_method);

        $voucher = Voucher::first();
        $this->assertSame('compra', $voucher->operation);
        $this->assertEquals(1000.00, (float) $voucher->taxable_amount);
        $this->assertEquals(180.00, (float) $voucher->igv);
        $this->assertEquals(1180.00, (float) $voucher->total);

        $entry = $voucher->journalEntry()->with('lines.account')->first();
        $this->assertEquals(1180.00, (float) $entry->total_debit);
        $this->assertEquals(1180.00, (float) $entry->total_credit);
        $this->assertEqualsCanonicalizing(['6011', '40111', '4212'], $entry->lines->pluck('account.code')->all());

        // Registro de compras con el comprobante
        $this->actingAs($this->analyst)
            ->getJson("/api/v1/ledger/books/registro_compras?client_id={$this->client->id}&year={$voucher->issue_date->year}&month={$voucher->issue_date->month}")
            ->assertOk()->assertJsonPath('data.totals.total', 1180);
    }

    public function test_duplicate_voucher_is_marked_observed(): void
    {
        $this->actingAs($this->clientUser)->postJson('/api/v1/documents', [
            'files' => [UploadedFile::fake()->createWithContent('a.xml', $this->purchaseXml(5))], 'category' => 'comprobante',
        ])->assertCreated();
        // Mismo comprobante con distinto archivo (otro hash)
        $this->actingAs($this->clientUser)->postJson('/api/v1/documents', [
            'files' => [UploadedFile::fake()->createWithContent('b.xml', $this->purchaseXml(5)."\n")], 'category' => 'comprobante',
        ])->assertCreated();

        $this->assertSame(1, Voucher::count());
        $this->assertSame('observado', Document::latest('id')->first()->status);
    }

    public function test_return_and_resubmit_flow(): void
    {
        $this->actingAs($this->clientUser)->postJson('/api/v1/documents', [
            'files' => [UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf')], 'category' => 'comprobante',
        ])->assertCreated();
        $doc = Document::first();

        $this->actingAs($this->analyst)->postJson("/api/v1/documents/{$doc->id}/return", ['reason' => 'Ilegible'])->assertOk();
        $this->assertSame('devuelto', $doc->fresh()->status);

        $this->actingAs($this->clientUser)->postJson("/api/v1/documents/{$doc->id}/resubmit", [
            'file' => UploadedFile::fake()->createWithContent('ok.xml', $this->purchaseXml(9)),
        ])->assertCreated();

        $new = Document::where('parent_id', $doc->id)->first();
        $this->assertNotNull($new);
        $this->assertSame('procesado', $new->status);

        $this->actingAs($this->supervisor)->getJson('/api/v1/reports/R3?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertJsonPath('data.rows.0.devueltos', 1)->assertJsonPath('data.rows.0.reingresados', 1);
    }

    public function test_client_isolation(): void
    {
        $doc = Document::create([
            'client_id' => $this->other->id, 'uploaded_by' => $this->supervisor->id, 'original_name' => 'x.pdf',
            'path' => 'x', 'hash' => 'h', 'status' => 'ingresado',
        ]);

        $this->actingAs($this->clientUser)->getJson("/api/v1/documents/{$doc->id}")->assertForbidden();
        $this->actingAs($this->analyst)->getJson("/api/v1/documents/{$doc->id}")->assertForbidden();
        $this->actingAs($this->supervisor)->getJson("/api/v1/documents/{$doc->id}")->assertOk();
        $this->actingAs($this->clientUser)->getJson('/api/v1/documents')->assertOk()->assertJsonCount(0, 'data');

        // Externos no acceden a rutas internas
        $this->actingAs($this->clientUser)->getJson('/api/v1/vouchers')->assertForbidden();
        // Analista no administra usuarios
        $this->actingAs($this->analyst)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_client_permission_limits(): void
    {
        $this->clientUser->syncPermissions(['documentos.subir']);
        $this->actingAs($this->clientUser)->getJson('/api/v1/conversations')->assertForbidden();
    }

    public function test_chat_response_metrics(): void
    {
        $this->actingAs($this->clientUser)->postJson('/api/v1/conversations', [
            'subject' => 'Consulta IGV', 'category' => 'tributario', 'body' => '¿Cuándo vence?',
        ])->assertCreated();
        $conv = Conversation::first();
        $this->assertSame('abierta', $conv->status);
        $this->assertSame($this->analyst->id, $conv->assigned_to);

        $this->actingAs($this->analyst)->postJson("/api/v1/conversations/{$conv->id}/messages", ['body' => 'El 18.'])->assertCreated();
        $conv->refresh();
        $this->assertSame('respondida', $conv->status);
        $this->assertNotNull($conv->first_response_at);

        $this->actingAs($this->supervisor)->getJson('/api/v1/reports/R4')
            ->assertOk()->assertJsonPath('data.rows.0.contestadas', 1)->assertJsonPath('data.rows.0.pct_contestadas', 100);
    }

    public function test_all_reports_render(): void
    {
        foreach (array_keys(\App\Services\Reports\ReportService::CATALOG) as $code) {
            $this->actingAs($this->supervisor)->getJson("/api/v1/reports/{$code}")->assertOk()->assertJsonPath('data.code', $code);
        }
        $this->actingAs($this->clientUser)->getJson('/api/v1/reports/R2')->assertForbidden();
        $this->actingAs($this->clientUser)->getJson('/api/v1/reports/R1')->assertOk();
    }

    public function test_ple_export_and_financial_report_generation(): void
    {
        $this->actingAs($this->clientUser)->postJson('/api/v1/documents', [
            'files' => [UploadedFile::fake()->createWithContent('a.xml', $this->purchaseXml())], 'category' => 'comprobante',
        ]);
        $d = now()->subDay();

        $res = $this->actingAs($this->analyst)->get("/api/v1/ledger/ple?client_id={$this->client->id}&year={$d->year}&month={$d->month}&operation=compra");
        $res->assertOk();
        $this->assertStringContainsString('|F010|', $res->getContent());
        $this->assertStringContainsString('LE'.$this->client->ruc, $res->headers->get('Content-Disposition'));

        $this->actingAs($this->analyst)->postJson('/api/v1/financial-reports/generate', [
            'client_id' => $this->client->id, 'type' => 'situacion_financiera', 'year' => $d->year, 'month' => $d->month,
        ])->assertCreated();

        // El cliente no ve el borrador hasta que se publique
        $this->actingAs($this->clientUser)->getJson('/api/v1/financial-reports')->assertJsonCount(0, 'data');
        $id = \App\Models\FinancialReport::first()->id;
        $this->actingAs($this->analyst)->patchJson("/api/v1/financial-reports/{$id}", ['action' => 'publish'])->assertForbidden();
        $this->actingAs($this->supervisor)->patchJson("/api/v1/financial-reports/{$id}", ['action' => 'publish'])->assertOk();
        $this->actingAs($this->clientUser)->getJson('/api/v1/financial-reports')->assertJsonCount(1, 'data');
    }

    public function test_manual_journal_entry_must_balance(): void
    {
        $caja = \App\Models\Account::where('code', '101')->value('id');
        $capital = \App\Models\Account::where('code', '501')->value('id');

        $this->actingAs($this->analyst)->postJson('/api/v1/journal-entries', [
            'client_id' => $this->client->id, 'date' => now()->toDateString(), 'book' => 'diario', 'description' => 'Aporte',
            'lines' => [['account_id' => $caja, 'debit' => 100], ['account_id' => $capital, 'credit' => 90]],
        ])->assertStatus(422);

        $this->actingAs($this->analyst)->postJson('/api/v1/journal-entries', [
            'client_id' => $this->client->id, 'date' => now()->toDateString(), 'book' => 'diario', 'description' => 'Aporte',
            'lines' => [['account_id' => $caja, 'debit' => 100], ['account_id' => $capital, 'credit' => 100]],
        ])->assertCreated();
    }

    public function test_excel_and_pdf_exports(): void
    {
        $d = now();
        $this->actingAs($this->supervisor)->get('/api/v1/reports/R3?export=xlsx')
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($this->supervisor)->get('/api/v1/reports/R1?export=pdf')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->analyst)->get("/api/v1/ledger/books/eeff_anual/export?client_id={$this->client->id}&year={$d->year}&format=xlsx")
            ->assertOk();
    }

    public function test_import_vouchers_from_csv_other_sources(): void
    {
        $good = RucValidator::withCheckDigit('2010012345');
        $csv = "tipo,serie,numero,fecha,ruc_emisor,razon_social_emisor,doc_adquirente,nombre_adquirente,moneda,tipo_cambio,base,exonerado,inafecto,igv,otros,total\n"
            ."01,F777,55,".now()->subDay()->format('d/m/Y').",{$good},PROVEEDOR CSV,{$this->client->ruc},CLIENTE,PEN,1,100,0,0,18,0,118\n"
            ."01,F777,56,".now()->subDay()->format('d/m/Y').",20100123451,RUC MALO,{$this->client->ruc},CLIENTE,PEN,1,100,0,0,18,0,118\n";

        $this->actingAs($this->analyst)->post('/api/v1/vouchers/import', [
            'client_id' => $this->client->id,
            'file' => UploadedFile::fake()->createWithContent('compras.csv', $csv),
        ], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 1)->assertJsonCount(1, 'failed');

        $this->assertSame(1, Voucher::count());
    }
}
