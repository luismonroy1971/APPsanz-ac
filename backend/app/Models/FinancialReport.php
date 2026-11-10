<?php

namespace App\Models;

use App\Models\Concerns\ScopedToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialReport extends Model
{
    use ScopedToClient;

    public const CODES = [
        'R5' => 'Libros contables',
        'R6' => 'Estados financieros periódicos',
        'R7' => 'Estado financiero anual',
        'R8' => 'Otros informes',
    ];

    /** tipo => [código de reporte, título] */
    public const TYPES = [
        'registro_compras' => ['R5', 'Registro de Compras'],
        'registro_ventas' => ['R5', 'Registro de Ventas'],
        'libro_diario' => ['R5', 'Libro Diario'],
        'libro_mayor' => ['R5', 'Libro Mayor'],
        'balance_comprobacion' => ['R6', 'Balance de Comprobación'],
        'situacion_financiera' => ['R6', 'Estado de Situación Financiera'],
        'resultados' => ['R6', 'Estado de Resultados'],
        'eeff_anual' => ['R7', 'Estados Financieros Anuales'],
        'otro' => ['R8', 'Otro informe'],
    ];

    protected $fillable = [
        'client_id', 'period_id', 'report_code', 'type', 'title', 'year', 'month', 'format', 'path', 'original_name',
        'data', 'status', 'published', 'published_at', 'generated_by', 'reviewed_by',
    ];

    protected $hidden = ['path', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'published' => 'boolean', 'published_at' => 'datetime'];
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
