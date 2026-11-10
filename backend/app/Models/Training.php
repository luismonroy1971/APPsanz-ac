<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Training extends Model
{
    protected $fillable = [
        'created_by', 'client_id', 'type', 'title', 'description', 'scheduled_date', 'duration_hours', 'status',
        'progress', 'responsible_id', 'due_date', 'completed_at', 'path', 'original_name',
    ];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
            'duration_hours' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'training_participants')->withPivot('attended');
    }
}
