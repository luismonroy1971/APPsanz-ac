<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FinancialReportController;
use App\Http\Controllers\Api\InternalCommunicationController;
use App\Http\Controllers\Api\InternalDocumentController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TimeEntryController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\WorkingPaperController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Acceso APP: Login / Clave (externo e interno)
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/password', [AuthController::class, 'changePassword']);

        Route::get('meta', MetaController::class);
        Route::get('dashboard', DashboardController::class);
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('clients/{client}', [ClientController::class, 'show']);

        // C1 / S1: documentos (BD.1)
        Route::get('documents', [DocumentController::class, 'index']);
        Route::get('documents/{document}', [DocumentController::class, 'show']);
        Route::get('documents/{document}/download', [DocumentController::class, 'download']);
        Route::middleware('client.can:documentos.subir')->group(function () {
            Route::post('documents', [DocumentController::class, 'store']);
            Route::post('documents/{document}/resubmit', [DocumentController::class, 'resubmit']);
            Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
        });

        // C2 / S2: chat de consultas (BD.1)
        Route::middleware('client.can:consultas.usar')->group(function () {
            Route::get('conversations', [ConversationController::class, 'index']);
            Route::post('conversations', [ConversationController::class, 'store']);
            Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
            Route::post('conversations/{conversation}/messages', [ConversationController::class, 'reply']);
            Route::patch('conversations/{conversation}', [ConversationController::class, 'update']);
            Route::get('messages/{message}/attachment', [ConversationController::class, 'attachment']);
        });

        // R5-R8 publicados (BD.2)
        Route::middleware('client.can:informes.ver')->group(function () {
            Route::get('financial-reports', [FinancialReportController::class, 'index']);
            Route::get('financial-reports/{financialReport}/download', [FinancialReportController::class, 'download']);
        });

        // Reportes de gestión
        Route::get('reports', [ReportController::class, 'catalog']);
        Route::get('reports/{code}', [ReportController::class, 'show']);

        // ===== Acceso interno: Analista + Supervisor + Socio =====
        Route::middleware('internal')->group(function () {
            Route::get('staff', [UserController::class, 'staff']);

            Route::post('documents/{document}/process', [DocumentController::class, 'process']);
            Route::post('documents/{document}/register', [DocumentController::class, 'register']);
            Route::post('documents/{document}/complete', [DocumentController::class, 'complete']);
            Route::post('documents/{document}/return', [DocumentController::class, 'return']);
            Route::patch('documents/{document}/assign', [DocumentController::class, 'assign']);

            // S1: comprobantes y contabilidad
            Route::get('vouchers', [VoucherController::class, 'index']);
            Route::post('vouchers', [VoucherController::class, 'store']);
            Route::post('vouchers/import', [VoucherController::class, 'import']);
            Route::get('vouchers/{voucher}', [VoucherController::class, 'show']);
            Route::put('vouchers/{voucher}', [VoucherController::class, 'update']);
            Route::post('vouchers/{voucher}/payment', [VoucherController::class, 'payment']);
            Route::post('vouchers/{voucher}/void', [VoucherController::class, 'void']);

            Route::get('accounts', [JournalEntryController::class, 'accounts']);
            Route::get('journal-entries', [JournalEntryController::class, 'index']);
            Route::post('journal-entries', [JournalEntryController::class, 'store']);
            Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show']);
            Route::post('journal-entries/{journalEntry}/void', [JournalEntryController::class, 'void']);

            // Libros, EEFF, impuestos, PLE
            Route::get('ledger/books/{type}', [LedgerController::class, 'book']);
            Route::get('ledger/books/{type}/export', [LedgerController::class, 'export']);
            Route::get('ledger/ple', [LedgerController::class, 'ple']);
            Route::get('ledger/taxes', [LedgerController::class, 'taxes']);
            Route::get('ledger/open-items', [LedgerController::class, 'openItems']);
            Route::get('periods', [LedgerController::class, 'periods']);
            Route::patch('periods/{period}', [LedgerController::class, 'updatePeriod']);

            Route::post('financial-reports', [FinancialReportController::class, 'store']);
            Route::post('financial-reports/generate', [FinancialReportController::class, 'generate']);
            Route::patch('financial-reports/{financialReport}', [FinancialReportController::class, 'update']);
            Route::delete('financial-reports/{financialReport}', [FinancialReportController::class, 'destroy']);

            // S3: procesos y horas
            Route::get('tasks', [TaskController::class, 'index']);
            Route::post('tasks', [TaskController::class, 'store']);
            Route::post('tasks/generate-month', [TaskController::class, 'generateMonth']);
            Route::patch('tasks/{task}', [TaskController::class, 'update']);
            Route::delete('tasks/{task}', [TaskController::class, 'destroy']);

            Route::get('time-entries', [TimeEntryController::class, 'index']);
            Route::post('time-entries', [TimeEntryController::class, 'store']);
            Route::post('time-entries/approve', [TimeEntryController::class, 'approve']);
            Route::put('time-entries/{timeEntry}', [TimeEntryController::class, 'update']);
            Route::delete('time-entries/{timeEntry}', [TimeEntryController::class, 'destroy']);

            // BD.3: R9, R11, R12, documentos internos
            Route::get('working-papers', [WorkingPaperController::class, 'index']);
            Route::post('working-papers', [WorkingPaperController::class, 'store']);
            Route::post('working-papers/{workingPaper}', [WorkingPaperController::class, 'update']);
            Route::get('working-papers/{workingPaper}/download', [WorkingPaperController::class, 'download']);
            Route::delete('working-papers/{workingPaper}', [WorkingPaperController::class, 'destroy']);

            Route::get('communications', [InternalCommunicationController::class, 'index']);
            Route::post('communications', [InternalCommunicationController::class, 'store']);
            Route::get('communications/{communication}', [InternalCommunicationController::class, 'show']);
            Route::post('communications/{communication}/acknowledge', [InternalCommunicationController::class, 'acknowledge']);
            Route::get('communications/{communication}/attachment', [InternalCommunicationController::class, 'attachment']);

            Route::get('trainings', [TrainingController::class, 'index']);
            Route::post('trainings', [TrainingController::class, 'store']);
            Route::post('trainings/{training}', [TrainingController::class, 'update']);
            Route::get('trainings/{training}/download', [TrainingController::class, 'download']);
            Route::delete('trainings/{training}', [TrainingController::class, 'destroy']);

            Route::get('internal-documents', [InternalDocumentController::class, 'index']);
            Route::post('internal-documents', [InternalDocumentController::class, 'store']);
            Route::get('internal-documents/{internalDocument}/download', [InternalDocumentController::class, 'download']);
            Route::delete('internal-documents/{internalDocument}', [InternalDocumentController::class, 'destroy']);

            // Administración: socio y supervisor
            Route::middleware('role:socio|supervisor')->group(function () {
                Route::post('clients', [ClientController::class, 'store']);
                Route::put('clients/{client}', [ClientController::class, 'update']);
                Route::delete('clients/{client}', [ClientController::class, 'destroy']);
                Route::put('clients/{client}/team', [ClientController::class, 'assignTeam']);

                Route::get('users', [UserController::class, 'index']);
                Route::post('users', [UserController::class, 'store']);
                Route::put('users/{user}', [UserController::class, 'update']);
                Route::delete('users/{user}', [UserController::class, 'destroy']);
            });
        });
    });
});
