<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinancialReport;
use App\Models\Task;
use App\Models\Voucher;
use App\Services\Accounting\BookTables;
use App\Services\Ai\InvoiceExtractor;
use Illuminate\Http\JsonResponse;

/** Catálogos para formularios del frontend. */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'document_categories' => [
                'comprobante' => 'Comprobante de pago (factura, boleta, NC, ND)',
                'bancario' => 'Estado de cuenta / documento bancario',
                'planilla' => 'Planilla / RRHH',
                'contrato' => 'Contrato',
                'tributario' => 'Documento tributario (SUNAT)',
                'otro' => 'Otro',
            ],
            'document_statuses' => [
                'ingresado' => 'Ingresado', 'en_proceso' => 'En proceso', 'procesado' => 'Procesado',
                'observado' => 'Observado', 'devuelto' => 'Devuelto', 'reingresado' => 'Reingresado', 'error' => 'Error',
            ],
            'voucher_types' => Voucher::DOCUMENT_TYPES,
            'task_areas' => ['contable' => 'Contable', 'tributario' => 'Tributario', 'rrhh' => 'RRHH', 'tesoreria' => 'Tesorería'],
            'task_activities' => Task::ACTIVITIES,
            'task_statuses' => ['pendiente' => 'Pendiente', 'en_proceso' => 'En proceso', 'en_revision' => 'En revisión', 'completado' => 'Completado'],
            'report_codes' => FinancialReport::CODES,
            'report_types' => collect(FinancialReport::TYPES)->map(fn ($v) => ['code' => $v[0], 'label' => $v[1]]),
            'books' => BookTables::BOOKS,
            'conversation_categories' => ['contable' => 'Contable', 'tributario' => 'Tributario', 'rrhh' => 'RRHH', 'tesoreria' => 'Tesorería', 'general' => 'General'],
            'tax_regimes' => ['general' => 'Régimen General', 'mype' => 'Régimen MYPE Tributario', 'especial' => 'Régimen Especial (RER)', 'rus' => 'Nuevo RUS'],
            'client_permissions' => [
                'documentos.subir' => 'Ingresar documentos (C1)',
                'consultas.usar' => 'Chat de consultas (C2)',
                'informes.ver' => 'Ver informes y libros (R5-R8)',
            ],
            'ai_extraction' => InvoiceExtractor::enabled(),
        ]]);
    }
}
