<?php

namespace App\Models;

use App\Models\Concerns\ScopedToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use ScopedToClient;

    public const AREAS = ['contable', 'tributario', 'rrhh', 'tesoreria'];

    /** Actividades del proceso S3 según la propuesta. */
    public const ACTIVITIES = [
        'validacion' => 'Validación de documentos y registros sistemas',
        'conciliaciones' => 'Conciliaciones / ajustes / opiniones / implementaciones',
        'libros' => 'Elaboración y registro de libros / otros similares',
        'impuestos' => 'Estimados impuestos / otros pagos / cuentas x cobrar',
        'eeff' => 'Estados financieros periódicos / final / DJ / otros',
        'otros_informes' => 'Otros informes',
    ];

    public const STATUSES = ['pendiente', 'en_proceso', 'en_revision', 'completado'];

    protected $fillable = [
        'client_id', 'period_id', 'assigned_to', 'reviewed_by', 'created_by', 'area', 'activity', 'title',
        'description', 'deliverable', 'status', 'priority', 'due_date', 'completed_at', 'estimated_hours',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
