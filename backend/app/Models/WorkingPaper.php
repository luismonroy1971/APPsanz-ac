<?php

namespace App\Models;

use App\Models\Concerns\ScopedToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingPaper extends Model
{
    use ScopedToClient;

    protected $fillable = [
        'client_id', 'period_id', 'task_id', 'prepared_by', 'reviewed_by', 'reference', 'title', 'area',
        'description', 'conclusion', 'path', 'original_name', 'status', 'reviewed_at',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
