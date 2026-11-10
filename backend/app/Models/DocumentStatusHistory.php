<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentStatusHistory extends Model
{
    protected $table = 'document_status_history';

    public const UPDATED_AT = null;

    protected $fillable = ['document_id', 'user_id', 'from_status', 'to_status', 'comment'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
