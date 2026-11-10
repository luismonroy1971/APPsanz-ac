<?php

namespace App\Models;

use App\Models\Concerns\ScopedToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model
{
    use ScopedToClient;

    protected $fillable = ['client_id', 'year', 'month', 'status', 'closed_at', 'closed_by'];

    protected $appends = ['label'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function getLabelAttribute(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public static function resolve(int $clientId, string $date): self
    {
        $ts = strtotime($date);

        return static::firstOrCreate([
            'client_id' => $clientId,
            'year' => (int) date('Y', $ts),
            'month' => (int) date('n', $ts),
        ]);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
