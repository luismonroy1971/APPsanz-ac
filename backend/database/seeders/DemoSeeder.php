<?php

namespace Database\Seeders;

use App\Http\Controllers\Api\TaskController;
use App\Models\Account;
use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\InternalCommunication;
use App\Models\Period;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Training;
use App\Models\User;
use App\Models\WorkingPaper;
use App\Services\Accounting\FinancialReportGenerator;
use App\Services\Accounting\JournalService;
use App\Services\Documents\DocumentProcessor;
use App\Services\Sunat\RucValidator;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\SampleUbl;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Datos de demostración para recorrer toda la propuesta APPsanz&ac.
 * Credenciales de prueba: ver README.md (contraseña común de demo: Demo2026!).
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo2026!';

    private CarbonImmutable $today;
    private array $staff = [];

    public function run(): void
    {
        mt_srand(2026);
        $this->today = CarbonImmutable::today();

        $this->createStaff();
        $clients = $this->createClients();

        $suppliers = [
            [RucValidator::withCheckDigit('2010012345'), 'DISTRIBUIDORA NACIONAL DEL PERU S.A.C.'],
            [RucValidator::withCheckDigit('2050987654'), 'TECNOLOGIA Y SISTEMAS LIMA S.A.'],
            [RucValidator::withCheckDigit('2060111222'), 'TRANSPORTES RAPIDOS DEL CENTRO E.I.R.L.'],
            [RucValidator::withCheckDigit('2045566778'), 'INMOBILIARIA LOS PORTALES DEL SUR S.A.C.'],
            [RucValidator::withCheckDigit('2033344455'), 'SUMINISTROS INDUSTRIALES ANDES S.A.C.'],
        ];
        $customers = [
            [RucValidator::withCheckDigit('2070077788'), 'CORPORACION MINERA HORIZONTE S.A.'],
            [RucValidator::withCheckDigit('2051122334'), 'SUPERMERCADOS DEL NORTE S.A.C.'],
            [RucValidator::withCheckDigit('2049988776'), 'CONSTRUCTORA VILLA REAL S.A.C.'],
            ['45781236', 'ROSA MARIA QUISPE HUAMAN'],
        ];

        foreach ($clients as $idx => $client) {
            $this->openingEntry($client);
            $this->documents($client, $suppliers, $customers, $idx);
            $this->manualEntries($client);
            $this->conversations($client);
            $this->tasksAndHours($client);
            $this->accessLogs($client);
            $this->reports($client, $idx);
            $this->workingPapers($client);
        }

        $this->communications();
        $this->trainings($clients);
        $this->staffAccessLogs();
        $this->writeSampleFiles($clients[0], $suppliers);
    }

    private function createStaff(): void
    {
        $people = [
            ['socio', 'Carlos Sanz Arévalo', 'socio@sanzac.pe', 'Socio', 180],
            ['supervisor', 'María Rivas Castillo', 'supervisor@sanzac.pe', 'Supervisora contable', 110],
            ['analista', 'Jorge Paredes Linares', 'analista@sanzac.pe', 'Analista contable', 60],
            ['analista', 'Lucía Torres Medina', 'analista2@sanzac.pe', 'Analista tributario', 60],
            ['analista', 'Diego Huamán Rojas', 'analista3@sanzac.pe', 'Analista de RRHH', 55],
        ];
        foreach ($people as [$role, $name, $email, $position, $cost]) {
            $u = User::create([
                'name' => $name, 'email' => $email, 'type' => 'interno', 'position' => $position,
                'hourly_cost' => $cost, 'password' => self::PASSWORD, 'email_verified_at' => now(),
            ]);
            $u->assignRole($role);
            $this->staff[$email] = $u;
        }
    }

    /** @return array<int, Client> */
    private function createClients(): array
    {
        $data = [
            [RucValidator::withCheckDigit('2060123456'), 'COMERCIAL ANDINA S.A.C.', 'Comercial Andina', 'general',
                [['Ana Gutiérrez Flores', 'cliente@andina.pe', 'Gerente de Administración', null],
                 ['Pedro Salazar Núñez', 'contabilidad@andina.pe', 'Asistente contable', null]],
                ['analista@sanzac.pe', 'analista2@sanzac.pe']],
            [RucValidator::withCheckDigit('2060765432'), 'INVERSIONES PACIFICO E.I.R.L.', 'Inversiones Pacífico', 'mype',
                [['Roberto Chávez Díaz', 'cliente@pacifico.pe', 'Gerente General', null]],
                ['analista2@sanzac.pe']],
            [RucValidator::withCheckDigit('2055544433'), 'SERVICIOS TECNICOS DEL SUR S.A.', 'SerTec Sur', 'general',
                [['Carmen Vega Ríos', 'cliente@sertecsur.pe', 'Contadora interna', null],
                 ['Luis Mendoza Paz', 'tesoreria@sertecsur.pe', 'Tesorero', ['documentos.subir']]],
                ['analista3@sanzac.pe', 'analista@sanzac.pe']],
        ];

        $clients = [];
        foreach ($data as [$ruc, $name, $trade, $regime, $users, $analysts]) {
            $client = Client::create([
                'ruc' => $ruc, 'business_name' => $name, 'trade_name' => $trade, 'tax_regime' => $regime,
                'address' => 'Av. Javier Prado Este 1234, San Isidro, Lima',
                'contact_name' => $users[0][0], 'contact_email' => $users[0][1], 'contact_phone' => '01 4405566',
                'contract_number' => 'CT-'.$this->today->year.'-'.str_pad((string) (count($clients) + 1), 3, '0', STR_PAD_LEFT),
                'contract_start' => $this->today->subYear()->startOfYear(), 'contract_end' => $this->today->addYear()->endOfYear(),
                'max_external_users' => 5,
                'access_notes' => 'Acceso definido en contrato. Usuarios autorizados por la Gerencia del cliente. Reporte semanal de accesos al cliente.',
            ]);
            foreach ($users as [$uName, $email, $position, $perms]) {
                $u = User::create([
                    'client_id' => $client->id, 'name' => $uName, 'email' => $email, 'type' => 'externo',
                    'position' => $position, 'password' => self::PASSWORD, 'email_verified_at' => now(),
                ]);
                $u->assignRole('cliente');
                $u->syncPermissions($perms ?? ['documentos.subir', 'consultas.usar', 'informes.ver']);
            }
            $team = [$this->staff['supervisor@sanzac.pe']->id => ['role' => 'supervisor'], $this->staff['socio@sanzac.pe']->id => ['role' => 'socio']];
            foreach ($analysts as $a) {
                $team[$this->staff[$a]->id] = ['role' => 'analista'];
            }
            $client->team()->sync($team);
            $clients[] = $client;
        }

        return $clients;
    }

    private function openingEntry(Client $client): void
    {
        $date = $this->today->subMonths(3)->startOfMonth()->toDateString();
        app(JournalService::class)->create([
            'client_id' => $client->id, 'date' => $date, 'book' => 'diario',
            'description' => 'Asiento de apertura del periodo', 'origin' => 'manual',
            'created_by' => $this->staff['supervisor@sanzac.pe']->id,
        ], [
            ['account_id' => Account::idByCode('1041'), 'debit' => 85000, 'credit' => 0, 'description' => 'Saldo en bancos'],
            ['account_id' => Account::idByCode('20111'), 'debit' => 42000, 'credit' => 0, 'description' => 'Inventario inicial'],
            ['account_id' => Account::idByCode('3361'), 'debit' => 18000, 'credit' => 0, 'description' => 'Equipos de cómputo'],
            ['account_id' => Account::idByCode('501'), 'debit' => 0, 'credit' => 120000, 'description' => 'Capital social'],
            ['account_id' => Account::idByCode('5911'), 'debit' => 0, 'credit' => 25000, 'description' => 'Resultados acumulados'],
        ]);
    }

    private function documents(Client $client, array $suppliers, array $customers, int $idx): void
    {
        $processor = app(DocumentProcessor::class);
        $clientUser = $client->users()->first();
        $analyst = $client->team()->wherePivot('role', 'analista')->first();
        $products = [
            ['Laptop corporativa 14"', 1, 2850.00], ['Papel bond A4 x 500 hojas', 20, 14.50], ['Servicio de transporte de carga', 1, 1200.00],
            ['Alquiler de oficina', 1, 3500.00], ['Tóner impresora láser', 4, 189.90], ['Mantenimiento preventivo de equipos', 1, 980.00],
            ['Útiles de escritorio', 15, 8.40], ['Licencia de software anual', 2, 1450.00],
        ];
        $services = [['Venta de mercadería - lote', 10, 420.00], ['Servicio de consultoría técnica', 1, 6800.00], ['Venta de repuestos industriales', 25, 96.50], ['Servicio de instalación', 1, 2300.00]];

        $seq = 100 + $idx * 50;
        for ($m = 2; $m >= 0; $m--) {
            $month = $this->today->subMonths($m)->startOfMonth();
            $maxDay = $m === 0 ? max(1, $this->today->day - 1) : $month->daysInMonth;
            $count = $m === 0 ? 4 : 7;

            for ($i = 0; $i < $count; $i++) {
                $isSale = $i % 3 === 0;
                $date = $month->setDay(mt_rand(1, $maxDay));
                if ($isSale) {
                    $cust = $customers[array_rand($customers)];
                    $lines = [$services[array_rand($services)], $services[array_rand($services)]];
                    $isBoleta = strlen($cust[0]) === 8;
                    $xml = SampleUbl::invoice($isBoleta ? 'B001' : 'F001', ++$seq, $date->toDateString(), $client->ruc, $client->business_name, $cust[0], $cust[1], $lines, $isBoleta ? '03' : '01');
                    $name = $client->ruc.'-'.($isBoleta ? '03-B001' : '01-F001').'-'.$seq.'.xml';
                } else {
                    $sup = $suppliers[array_rand($suppliers)];
                    $lines = [$products[array_rand($products)]];
                    if (mt_rand(0, 1)) {
                        $lines[] = $products[array_rand($products)];
                    }
                    $num = mt_rand(1000, 9999);
                    $xml = SampleUbl::invoice('F'.str_pad((string) mt_rand(1, 9), 3, '0', STR_PAD_LEFT), $num, $date->toDateString(), $sup[0], $sup[1], $client->ruc, $client->business_name, $lines);
                    $name = $sup[0].'-01-'.$num.'.xml';
                }
                $doc = $this->storeDocument($client, $clientUser, $name, $xml, 'application/xml', $date->addDays(mt_rand(0, 3)));
                $processor->process($doc, $analyst);
                $doc->refresh();
                $doc->update(['processed_at' => $doc->created_at->addHours(mt_rand(2, 30)), 'assigned_to' => $analyst?->id]);
                $doc->history()->update(['created_at' => $doc->created_at]);
            }
        }

        // Documento devuelto y reingresado (R3)
        $returnedAt = $this->today->subDays(12);
        $bad = $this->storeDocument($client, $clientUser, 'factura_ilegible_scan.pdf', $this->fakePdf('Factura escaneada ilegible'), 'application/pdf', $returnedAt->subDay(), 'comprobante');
        $bad->update(['status' => 'devuelto', 'return_reason' => 'La imagen está borrosa y no se lee el número del comprobante. Favor enviar el XML o un escaneo legible.', 'returned_at' => $returnedAt, 'assigned_to' => $analyst?->id]);
        $bad->history()->create(['user_id' => $analyst?->id, 'from_status' => 'ingresado', 'to_status' => 'devuelto', 'comment' => $bad->return_reason, 'created_at' => $returnedAt]);
        if ($idx !== 1) {
            $re = $this->storeDocument($client, $clientUser, 'factura_corregida.pdf', $this->fakePdf('Factura reenviada'), 'application/pdf', $returnedAt->addDays(2), 'comprobante');
            $re->update(['parent_id' => $bad->id, 'status' => 'en_proceso', 'processing_method' => 'manual', 'assigned_to' => $analyst?->id]);
            $re->history()->create(['user_id' => $clientUser->id, 'from_status' => null, 'to_status' => 'reingresado', 'comment' => "Reingreso del documento #{$bad->id}.", 'created_at' => $re->created_at]);
        }

        // Documentos no transaccionales pendientes de revisión
        $bank = $this->storeDocument($client, $clientUser, 'estado_cuenta_bcp_'.$this->today->subMonth()->format('Y_m').'.pdf', $this->fakePdf('Estado de cuenta bancario'), 'application/pdf', $this->today->subDays(3), 'bancario');
        $bank->update(['period' => $this->today->subMonth()->format('Y-m'), 'status' => 'en_proceso']);
        $this->storeDocument($client, $clientUser, 'planilla_'.$this->today->subMonth()->format('Y_m').'.xlsx', 'planilla', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->today->subDays(1), 'planilla');
    }

    private function storeDocument(Client $client, User $user, string $name, string $content, string $mime, CarbonImmutable $at, string $category = 'comprobante'): Document
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = "documents/{$client->id}/".Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $content);

        $doc = new Document([
            'client_id' => $client->id, 'uploaded_by' => $user->id, 'category' => $category, 'source' => 'portal',
            'period' => $at->format('Y-m'), 'original_name' => $name, 'path' => $path, 'mime_type' => $mime,
            'extension' => $ext, 'size' => strlen($content), 'hash' => hash('sha256', $content.$name), 'status' => 'ingresado',
        ]);
        $doc->created_at = $at->setTime(mt_rand(8, 18), mt_rand(0, 59));
        $doc->updated_at = $doc->created_at;
        $doc->save();
        $doc->history()->create(['user_id' => $user->id, 'to_status' => 'ingresado', 'comment' => 'Documento ingresado.', 'created_at' => $doc->created_at]);

        return $doc;
    }

    private function fakePdf(string $text): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj "
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj "
            ."4 0 obj<</Length 60>>stream\nBT /F1 18 Tf 60 760 Td ({$text}) Tj ET\nendstream endobj "
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    }

    private function manualEntries(Client $client): void
    {
        $journal = app(JournalService::class);
        $by = $this->staff['analista3@sanzac.pe']->id;
        for ($m = 2; $m >= 1; $m--) {
            $end = $this->today->subMonths($m)->endOfMonth()->toDateString();
            $journal->create(['client_id' => $client->id, 'date' => $end, 'book' => 'diario', 'description' => 'Provisión de planilla del mes', 'origin' => 'manual', 'created_by' => $by], [
                ['account_id' => Account::idByCode('6211'), 'debit' => 9500, 'credit' => 0],
                ['account_id' => Account::idByCode('6271'), 'debit' => 855, 'credit' => 0],
                ['account_id' => Account::idByCode('4031'), 'debit' => 0, 'credit' => 855],
                ['account_id' => Account::idByCode('417'), 'debit' => 0, 'credit' => 1235],
                ['account_id' => Account::idByCode('4111'), 'debit' => 0, 'credit' => 8265],
            ]);
            $journal->create(['client_id' => $client->id, 'date' => $end, 'book' => 'ajustes', 'description' => 'Depreciación mensual de equipos', 'origin' => 'ajuste', 'created_by' => $by], [
                ['account_id' => Account::idByCode('6814'), 'debit' => 375, 'credit' => 0],
                ['account_id' => Account::idByCode('3913'), 'debit' => 0, 'credit' => 375],
            ]);
            $journal->create(['client_id' => $client->id, 'date' => $end, 'book' => 'caja', 'description' => 'Pago de planilla', 'origin' => 'manual', 'created_by' => $by], [
                ['account_id' => Account::idByCode('4111'), 'debit' => 8265, 'credit' => 0],
                ['account_id' => Account::idByCode('1041'), 'debit' => 0, 'credit' => 8265],
            ]);
        }
    }

    private function conversations(Client $client): void
    {
        $clientUser = $client->users()->first();
        $analyst = $client->team()->wherePivot('role', 'analista')->first();
        $threads = [
            ['¿Cuándo vence la declaración del IGV de este mes?', 'tributario', [
                [true, 'Buenos días, ¿podrían confirmarnos la fecha de vencimiento del PDT 621 de este mes?'],
                [false, 'Buenos días. Según el cronograma SUNAT para su último dígito de RUC, vence el día 18. Le enviaremos el estimado de IGV tres días antes.'],
                [true, 'Perfecto, muchas gracias.'],
            ], 'cerrada', 20],
            ['Factura de proveedor sin XML', 'contable', [
                [true, 'Tenemos una factura de TRANSPORTES RAPIDOS que solo nos llegó en PDF. ¿La pueden registrar así?'],
                [false, 'Sí, súbala en la sección Documentos como comprobante; la registraremos manualmente y validaremos su estado en SUNAT.'],
            ], 'respondida', 9],
            ['Alta de nuevo trabajador en planilla', 'rrhh', [
                [true, 'Ingresa un nuevo colaborador el lunes. ¿Qué documentos necesitan para el T-Registro?'],
                [false, 'Necesitamos copia de DNI, contrato firmado, elección de sistema pensionario y datos bancarios. Puede adjuntarlos aquí.'],
                [true, 'Adjunto pronto. ¿El contrato puede ser a plazo fijo por 3 meses?'],
            ], 'en_seguimiento', 4],
            ['Consulta sobre detracciones', 'tributario', [
                [true, '¿El servicio de mantenimiento que nos facturaron está afecto a detracción?'],
            ], 'abierta', 1],
        ];

        foreach ($threads as [$subject, $cat, $msgs, $status, $daysAgo]) {
            $start = $this->today->subDays($daysAgo)->setTime(9, 15);
            $conv = new Conversation([
                'client_id' => $client->id, 'created_by' => $clientUser->id, 'assigned_to' => $analyst?->id,
                'subject' => $subject, 'category' => $cat, 'priority' => $cat === 'tributario' ? 'alta' : 'media', 'status' => $status,
            ]);
            $conv->created_at = $start;
            $conv->save();
            $t = $start;
            $firstResponse = null;
            foreach ($msgs as [$fromClient, $body]) {
                $t = $t->addMinutes(mt_rand(40, 400));
                $msg = $conv->messages()->make([
                    'user_id' => $fromClient ? $clientUser->id : $analyst?->id ?? $this->staff['analista@sanzac.pe']->id,
                    'from_client' => $fromClient, 'body' => $body, 'read_at' => $t->addMinutes(30),
                ]);
                $msg->created_at = $t;
                $msg->save();
                if (! $fromClient && ! $firstResponse) {
                    $firstResponse = $t;
                }
            }
            $conv->update(['last_message_at' => $t, 'first_response_at' => $firstResponse, 'closed_at' => $status === 'cerrada' ? $t : null]);
        }
    }

    private function tasksAndHours(Client $client): void
    {
        $analyst = $client->team()->wherePivot('role', 'analista')->first();
        $supervisor = $this->staff['supervisor@sanzac.pe'];

        foreach ([2, 1] as $m) {
            $month = $this->today->subMonths($m);
            $period = Period::firstOrCreate(['client_id' => $client->id, 'year' => $month->year, 'month' => $month->month]);
            $base = $month->startOfMonth()->addMonth();
            foreach (TaskController::TEMPLATE as [$area, $activity, $title, $deliverable, $hours, $day]) {
                $due = $base->setDay($day);
                $done = $m === 2 || $due->lt($this->today->subDays(2)) && mt_rand(0, 4) > 0;
                Task::create([
                    'client_id' => $client->id, 'period_id' => $period->id, 'assigned_to' => $analyst?->id,
                    'reviewed_by' => $supervisor->id, 'created_by' => $supervisor->id, 'area' => $area, 'activity' => $activity,
                    'title' => $title.' - '.$period->label, 'deliverable' => $deliverable, 'estimated_hours' => $hours,
                    'due_date' => $due, 'status' => $done ? 'completado' : ($due->isPast() ? 'en_revision' : 'pendiente'),
                    'completed_at' => $done ? $due->subDays(mt_rand(-2, 3))->setTime(17, 0) : null,
                ]);
            }
            if ($m === 2) {
                $period->update(['status' => 'cerrado', 'closed_at' => $base->setDay(26), 'closed_by' => $supervisor->id]);
            }
        }

        // Horas hombre de las últimas 6 semanas (R2)
        $team = $client->team()->get();
        for ($d = 42; $d >= 0; $d--) {
            $day = $this->today->subDays($d);
            if ($day->isWeekend()) {
                continue;
            }
            foreach ($team as $member) {
                $role = $member->pivot->role;
                if ($role === 'socio' && mt_rand(0, 4) > 0) {
                    continue;
                }
                if ($role === 'supervisor' && mt_rand(0, 2) > 0) {
                    continue;
                }
                $task = Task::where('client_id', $client->id)->inRandomOrder()->first();
                TimeEntry::create([
                    'user_id' => $member->id, 'client_id' => $client->id, 'task_id' => $task?->id, 'date' => $day,
                    'hours' => $role === 'analista' ? mt_rand(4, 12) / 2 : mt_rand(1, 4) / 2,
                    'activity' => $task?->activity,
                    'description' => $role === 'analista' ? 'Registro y revisión de operaciones' : 'Revisión y supervisión',
                    'approved' => $d > 7, 'approved_by' => $d > 7 ? $this->staff['supervisor@sanzac.pe']->id : null,
                ]);
            }
        }
    }

    private function accessLogs(Client $client): void
    {
        foreach ($client->users as $u) {
            for ($d = 42; $d >= 0; $d--) {
                if (mt_rand(0, 9) < 4) {
                    continue;
                }
                AccessLog::create([
                    'user_id' => $u->id, 'client_id' => $client->id, 'event' => 'login', 'ip_address' => '190.42.'.mt_rand(1, 254).'.'.mt_rand(1, 254),
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0', 'created_at' => $this->today->subDays($d)->setTime(mt_rand(8, 19), mt_rand(0, 59)),
                ]);
            }
            $u->update(['last_login_at' => AccessLog::where('user_id', $u->id)->max('created_at')]);
        }
    }

    private function staffAccessLogs(): void
    {
        foreach ($this->staff as $u) {
            for ($d = 42; $d >= 0; $d--) {
                if ($this->today->subDays($d)->isWeekend()) {
                    continue;
                }
                AccessLog::create([
                    'user_id' => $u->id, 'client_id' => null, 'event' => 'login', 'ip_address' => '10.0.0.'.mt_rand(10, 90),
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Edge/130.0', 'created_at' => $this->today->subDays($d)->setTime(8, mt_rand(0, 40)),
                ]);
            }
        }
    }

    private function reports(Client $client, int $idx): void
    {
        $generator = app(FinancialReportGenerator::class);
        $supervisor = $this->staff['supervisor@sanzac.pe'];
        $month = $this->today->subMonths(2);
        foreach (['registro_compras', 'registro_ventas', 'libro_diario', 'situacion_financiera', 'resultados'] as $type) {
            $r = $generator->generate($client, $type, $month->year, $month->month, $supervisor);
            $r->update(['status' => 'publicado', 'published' => true, 'published_at' => $month->addMonth()->setDay(22), 'reviewed_by' => $supervisor->id,
                'created_at' => $month->addMonth()->setDay(20)]);
        }
        $last = $this->today->subMonth();
        $r = $generator->generate($client, 'registro_compras', $last->year, $last->month, $this->staff['analista@sanzac.pe']);
        $r->update(['status' => $idx === 0 ? 'revisado' : 'borrador']);
    }

    private function workingPapers(Client $client): void
    {
        $analyst = $client->team()->wherePivot('role', 'analista')->first() ?? $this->staff['analista@sanzac.pe'];
        $period = Period::where('client_id', $client->id)->orderBy('year')->orderBy('month')->first();
        $papers = [
            ['A-1', 'Conciliación bancaria cuenta corriente BCP', 'contable', 'aprobado'],
            ['B-1', 'Análisis de cuentas por cobrar comerciales', 'contable', 'revisado'],
            ['T-1', 'Determinación del IGV y pago a cuenta', 'tributario', 'aprobado'],
            ['R-1', 'Cálculo de planilla, CTS y gratificaciones', 'rrhh', 'preparado'],
            ['TS-1', 'Flujo de caja proyectado', 'tesoreria', 'preparado'],
        ];
        foreach ($papers as [$ref, $title, $area, $status]) {
            $p = new WorkingPaper([
                'client_id' => $client->id, 'period_id' => $period?->id, 'prepared_by' => $analyst->id, 'reference' => $ref,
                'title' => $title, 'area' => $area, 'status' => $status,
                'description' => 'Procedimientos aplicados conforme al programa de trabajo del periodo.',
                'conclusion' => $status !== 'preparado' ? 'Sin observaciones significativas.' : null,
                'reviewed_by' => $status !== 'preparado' ? $this->staff['supervisor@sanzac.pe']->id : null,
                'reviewed_at' => $status !== 'preparado' ? $this->today->subDays(mt_rand(1, 6)) : null,
            ]);
            $p->created_at = $this->today->subDays(mt_rand(0, 6));
            $p->save();
        }
    }

    private function communications(): void
    {
        $socio = $this->staff['socio@sanzac.pe'];
        $supervisor = $this->staff['supervisor@sanzac.pe'];
        $items = [
            [$socio, 'directiva', 'Política de cierre contable mensual', 'Todos los periodos deben cerrarse a más tardar el día 26 del mes siguiente. Los entregables R5 y R6 deben estar revisados por el supervisor antes de su publicación al cliente.', 'alta', 8],
            [$supervisor, 'instruccion', 'Validación de comprobantes en SUNAT', 'Antes de registrar comprobantes recibidos en PDF, validar su estado en la consulta de validez de CPE de SUNAT y adjuntar la constancia en el papel de trabajo.', 'media', 3],
            [$supervisor, 'comunicado', 'Cronograma de obligaciones mensuales', 'Se publicó el cronograma de vencimientos SUNAT del próximo año. Planificar las actividades S3 en función del último dígito del RUC de cada cliente.', 'baja', 1],
        ];
        foreach ($items as [$author, $type, $title, $body, $priority, $daysAgo]) {
            $c = new InternalCommunication(['author_id' => $author->id, 'type' => $type, 'title' => $title, 'body' => $body, 'priority' => $priority, 'published_at' => $this->today->subDays($daysAgo)]);
            $c->created_at = $this->today->subDays($daysAgo)->setTime(10, 0);
            $c->save();
            foreach ($this->staff as $u) {
                if ($u->id === $author->id) {
                    continue;
                }
                $read = mt_rand(0, 3) > 0;
                $c->recipients()->attach($u->id, [
                    'read_at' => $read ? $c->created_at->addHours(mt_rand(1, 20)) : null,
                    'acknowledged_at' => $read && mt_rand(0, 1) ? $c->created_at->addHours(mt_rand(20, 30)) : null,
                ]);
            }
        }
    }

    private function trainings(array $clients): void
    {
        $socio = $this->staff['socio@sanzac.pe'];
        $analysts = collect($this->staff)->filter(fn ($u) => $u->hasRole('analista'))->pluck('id')->all();
        $items = [
            ['capacitacion', 'Actualización tributaria: cambios en el régimen MYPE', null, -10, 3, 'completado', 100],
            ['capacitacion', 'Uso de la plataforma APPsanz&ac y procesamiento de XML SUNAT', null, 2, 2, 'planificado', 0],
            ['recomendacion', 'Implementar control de caja chica con rendición semanal', 0, 15, null, 'en_curso', 60],
            ['recomendacion', 'Emitir facturas electrónicas desde el sistema del cliente (OSE)', 1, 25, null, 'planificado', 10],
            ['informe', 'Informe de control interno - primer semestre', 2, -5, null, 'completado', 100],
        ];
        foreach ($items as [$type, $title, $clientIdx, $days, $duration, $status, $progress]) {
            $t = Training::create([
                'created_by' => $socio->id, 'client_id' => $clientIdx !== null ? $clients[$clientIdx]->id : null, 'type' => $type, 'title' => $title,
                'description' => 'Registro de seguimiento del área de calidad de SANZ & AC.',
                'scheduled_date' => $type === 'capacitacion' ? $this->today->addDays($days) : null,
                'due_date' => $type !== 'capacitacion' ? $this->today->addDays($days) : null,
                'duration_hours' => $duration, 'status' => $status, 'progress' => $progress,
                'responsible_id' => $this->staff['supervisor@sanzac.pe']->id,
                'completed_at' => $status === 'completado' ? $this->today->addDays($days) : null,
            ]);
            if ($type === 'capacitacion') {
                $t->participants()->sync(array_fill_keys($analysts, ['attended' => $status === 'completado']));
            }
        }
    }

    /** Archivos XML de ejemplo para probar la carga desde el portal del cliente. */
    private function writeSampleFiles(Client $client, array $suppliers): void
    {
        $date = $this->today->toDateString();
        $files = [
            'factura-compra-F005-321.xml' => SampleUbl::invoice('F005', 321, $date, $suppliers[1][0], $suppliers[1][1], $client->ruc, $client->business_name, [['Monitor LED 27"', 3, 890.00], ['Teclado inalámbrico', 3, 120.00]]),
            'factura-venta-F001-900.xml' => SampleUbl::invoice('F001', 900, $date, $client->ruc, $client->business_name, RucValidator::withCheckDigit('2070077788'), 'CORPORACION MINERA HORIZONTE S.A.', [['Servicio de mantenimiento industrial', 1, 7500.00]]),
            'nota-credito-FC01-12.xml' => SampleUbl::invoice('FC01', 12, $date, $suppliers[1][0], $suppliers[1][1], $client->ruc, $client->business_name, [['Devolución teclado inalámbrico', 1, 120.00]], '07', 'PEN', 'F005-321'),
        ];
        foreach ($files as $name => $xml) {
            Storage::disk('local')->put("samples/{$name}", $xml);
        }
    }
}
