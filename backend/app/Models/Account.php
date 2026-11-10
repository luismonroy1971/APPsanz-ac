<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = ['code', 'name', 'type', 'nature', 'level', 'accepts_entries'];

    protected function casts(): array
    {
        return ['accepts_entries' => 'boolean'];
    }

    public static function idByCode(string $code): int
    {
        return (int) static::where('code', $code)->valueOrFail('id');
    }
}
