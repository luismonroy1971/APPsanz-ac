<?php

namespace App\Models;

use App\Models\Concerns\ScopedToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Document extends Model
{
    use ScopedToClient;

    public const STATUSES = ['ingresado', 'en_proceso', 'procesado', 'observado', 'devuelto', 'reingresado', 'error'];
    public const CATEGORIES = ['comprobante', 'bancario', 'planilla', 'contrato', 'tributario', 'otro'];

    protected $fillable = [
        'client_id', 'uploaded_by', 'parent_id', 'assigned_to', 'category', 'source', 'period', 'original_name',
        'path', 'mime_type', 'extension', 'size', 'hash', 'status', 'processing_method', 'extracted_data',
        'validation_errors', 'client_notes', 'return_reason', 'processed_at', 'returned_at',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'extracted_data' => 'array',
            'validation_errors' => 'array',
            'processed_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_id');
    }

    public function resubmissions(): HasMany
    {
        return $this->hasMany(Document::class, 'parent_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(DocumentStatusHistory::class)->orderBy('created_at');
    }

    public function voucher(): HasOne
    {
        return $this->hasOne(Voucher::class);
    }

    public function transitionTo(string $status, ?User $user = null, ?string $comment = null): void
    {
        $from = $this->status;
        $this->status = $status;
        $this->save();

        $this->history()->create([
            'user_id' => $user?->id,
            'from_status' => $from,
            'to_status' => $status,
            'comment' => $comment,
        ]);
    }
}
