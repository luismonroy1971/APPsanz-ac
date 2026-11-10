<?php

namespace App\Models\Concerns;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aísla los datos por cliente según el usuario autenticado:
 * - Usuario externo: solo su cliente.
 * - Analista: solo clientes asignados.
 * - Supervisor / Socio: todos.
 */
trait ScopedToClient
{
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->accessibleClientIds();
        if ($ids === null) {
            return $query;
        }

        return $query->whereIn($this->getTable().'.client_id', $ids);
    }

    public function scopeForClient(Builder $query, $clientId): Builder
    {
        return $clientId ? $query->where($this->getTable().'.client_id', $clientId) : $query;
    }
}
