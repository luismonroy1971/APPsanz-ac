<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ruc', 'business_name', 'trade_name', 'tax_regime', 'address', 'contact_name', 'contact_email',
        'contact_phone', 'contract_number', 'contract_start', 'contract_end', 'max_external_users',
        'access_notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'contract_start' => 'date:Y-m-d',
            'contract_end' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function team(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_assignments')->withPivot('role')->withTimestamps();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(Period::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->accessibleClientIds();

        return $ids === null ? $query : $query->whereIn('clients.id', $ids);
    }
}
