<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const TYPE_INTERNAL = 'interno';
    public const TYPE_EXTERNAL = 'externo';

    public const INTERNAL_ROLES = ['socio', 'supervisor', 'analista'];

    protected $guard_name = 'web';

    protected $fillable = [
        'client_id', 'name', 'email', 'type', 'position', 'phone', 'hourly_cost',
        'is_active', 'last_login_at', 'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'hourly_cost' => 'decimal:2',
            'password' => 'hashed',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedClients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_assignments')->withPivot('role')->withTimestamps();
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function isExternal(): bool
    {
        return $this->type === self::TYPE_EXTERNAL;
    }

    public function isInternal(): bool
    {
        return $this->type === self::TYPE_INTERNAL;
    }

    public function primaryRole(): ?string
    {
        foreach (['socio', 'supervisor', 'analista', 'cliente'] as $role) {
            if ($this->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /** Socio y supervisor ven todos los clientes. */
    public function seesAllClients(): bool
    {
        return $this->hasAnyRole(['socio', 'supervisor']);
    }

    /**
     * IDs de clientes accesibles. null = todos.
     *
     * @return array<int>|null
     */
    public function accessibleClientIds(): ?array
    {
        if ($this->isExternal()) {
            return $this->client_id ? [$this->client_id] : [];
        }
        if ($this->seesAllClients()) {
            return null;
        }

        return $this->assignedClients()->pluck('clients.id')->all();
    }

    public function canAccessClient(int $clientId): bool
    {
        $ids = $this->accessibleClientIds();

        return $ids === null || in_array($clientId, $ids, true);
    }
}
