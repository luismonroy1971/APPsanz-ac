<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Asignación del equipo interno (analista / supervisor / socio) a clientes
        Schema::create('client_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // analista | supervisor | socio
            $table->timestamps();
            $table->unique(['client_id', 'user_id', 'role']);
        });

        // R1: accesos realizados (BD.3)
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 20); // login | logout | failed
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Periodos contables por cliente
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 20)->default('abierto'); // abierto | en_revision | cerrado
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'year', 'month']);
        });

        // BD.1 / C1: documentos ingresados por el cliente
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('parent_id')->nullable()->constrained('documents')->nullOnDelete(); // reingresos
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 30)->default('comprobante'); // comprobante, bancario, planilla, contrato, tributario, otro
            $table->string('source', 20)->default('portal'); // portal | otras_fuentes | importacion
            $table->string('period', 7)->nullable(); // YYYY-MM
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 120)->nullable();
            $table->string('extension', 10)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('hash', 64)->index();
            $table->string('status', 20)->default('ingresado'); // ingresado, en_proceso, procesado, observado, devuelto, reingresado, error
            $table->string('processing_method', 20)->nullable(); // xml_ubl, ia, manual, excel
            $table->json('extracted_data')->nullable();
            $table->json('validation_errors')->nullable();
            $table->text('client_notes')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });

        Schema::create('document_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // BD.1 / C2-S2: chat de consultas
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->string('category', 30)->default('general'); // contable, tributario, rrhh, tesoreria, general
            $table->string('priority', 10)->default('media');
            $table->string('status', 20)->default('abierta'); // abierta, respondida, en_seguimiento, cerrada
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->boolean('from_client')->default(false);
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Plan contable (PCGE)
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name');
            $table->string('type', 20); // activo, pasivo, patrimonio, ingreso, gasto
            $table->string('nature', 10); // deudora | acreedora
            $table->unsignedTinyInteger('level')->default(1);
            $table->boolean('accepts_entries')->default(true);
            $table->timestamps();
        });

        // Comprobantes de pago (CPE) - S1
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 10); // compra | venta
            $table->string('document_type', 2); // 01 factura, 03 boleta, 07 NC, 08 ND
            $table->string('series', 4);
            $table->string('number', 8);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('issuer_ruc', 11);
            $table->string('issuer_name');
            $table->string('customer_doc_type', 1)->default('6');
            $table->string('customer_doc', 15)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('currency', 3)->default('PEN');
            $table->decimal('exchange_rate', 8, 3)->default(1);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('exempt_amount', 14, 2)->default(0);
            $table->decimal('unaffected_amount', 14, 2)->default(0);
            $table->decimal('igv', 14, 2)->default(0);
            $table->decimal('isc', 14, 2)->default(0);
            $table->decimal('other_charges', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('reference_doc')->nullable();
            $table->string('status', 20)->default('registrado'); // registrado, validado, anulado, observado
            $table->string('payment_status', 20)->default('pendiente'); // pendiente, parcial, pagado
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'operation', 'issuer_ruc', 'document_type', 'series', 'number'], 'vouchers_unique_cpe');
            $table->index(['client_id', 'issue_date']);
        });

        Schema::create('voucher_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->nullable();
            $table->string('description', 500);
            $table->string('unit', 10)->nullable();
            $table->decimal('quantity', 14, 4)->default(1);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('igv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
        });

        // Asientos contables
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 20);
            $table->date('date');
            $table->string('book', 20)->default('diario'); // compras, ventas, diario, caja, ajustes
            $table->string('description', 500);
            $table->string('origin', 20)->default('manual'); // automatico | manual | ajuste
            $table->string('status', 20)->default('registrado'); // borrador, registrado, anulado
            $table->decimal('total_debit', 14, 2)->default(0);
            $table->decimal('total_credit', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'date']);
        });

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
        });

        // S3: procesos contable / tributario / RRHH / tesorería
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('area', 20); // contable, tributario, rrhh, tesoreria
            $table->string('activity', 40); // validacion, conciliaciones, libros, impuestos, eeff, otros_informes
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('deliverable', 5)->nullable(); // R5, R6, R7, R8
            $table->string('status', 20)->default('pendiente'); // pendiente, en_proceso, en_revision, completado
            $table->string('priority', 10)->default('media');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('estimated_hours', 6, 2)->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });

        // R2: horas hombre trabajador x cliente
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('hours', 5, 2);
            $table->string('activity', 40)->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'date']);
        });

        // BD.2: R5 a R8
        Schema::create('financial_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_code', 5); // R5, R6, R7, R8
            $table->string('type', 40);
            $table->string('title');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month')->nullable();
            $table->string('format', 10)->default('pdf');
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->json('data')->nullable();
            $table->string('status', 20)->default('borrador'); // borrador, revisado, publicado
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // BD.3: R9 papeles de trabajo
        Schema::create('working_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prepared_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 20)->nullable();
            $table->string('title');
            $table->string('area', 20);
            $table->text('description')->nullable();
            $table->text('conclusion')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('status', 20)->default('preparado'); // preparado, revisado, aprobado
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // BD.3: R11 instrucciones / directivas / comunicaciones internas
        Schema::create('internal_communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // instruccion, directiva, comunicado
            $table->string('title');
            $table->text('body');
            $table->string('priority', 10)->default('media');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('communication_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internal_communication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unique(['internal_communication_id', 'user_id'], 'comm_recipient_unique');
        });

        // BD.3: R12 informes / capacitación / implementación de recomendaciones
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // capacitacion, informe, recomendacion
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->decimal('duration_hours', 5, 2)->nullable();
            $table->string('status', 20)->default('planificado'); // planificado, en_curso, implementado, completado, cancelado
            $table->unsignedTinyInteger('progress')->default(0);
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->timestamps();
        });

        Schema::create('training_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('attended')->default(false);
            $table->unique(['training_id', 'user_id']);
        });

        // BD.3: documentos internos
        Schema::create('internal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30)->default('general');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'internal_documents', 'training_participants', 'trainings', 'communication_recipients',
            'internal_communications', 'working_papers', 'financial_reports', 'time_entries', 'tasks',
            'journal_entry_lines', 'journal_entries', 'voucher_items', 'vouchers', 'accounts', 'messages',
            'conversations', 'document_status_history', 'documents', 'periods', 'access_logs', 'client_assignments',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
