<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class InternalCommunication extends Model
{
    protected $fillable = [
        'author_id', 'client_id', 'type', 'title', 'body', 'priority', 'attachment_path', 'attachment_name', 'published_at',
    ];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'communication_recipients')->withPivot('read_at', 'acknowledged_at');
    }
}
