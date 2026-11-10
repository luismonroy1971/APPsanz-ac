<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalDocument extends Model
{
    protected $fillable = ['uploaded_by', 'client_id', 'category', 'title', 'description', 'path', 'original_name', 'size'];

    protected $hidden = ['path'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
