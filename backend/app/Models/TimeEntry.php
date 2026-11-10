<?php

namespace App\Models;

use App\Models\Concerns\ScopedToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use ScopedToClient;

    protected $fillable = ['user_id', 'client_id', 'task_id', 'date', 'hours', 'activity', 'description', 'approved', 'approved_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'hours' => 'decimal:2', 'approved' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
