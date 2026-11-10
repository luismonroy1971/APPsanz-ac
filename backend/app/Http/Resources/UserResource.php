<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'type' => $this->type,
            'position' => $this->position,
            'phone' => $this->phone,
            'hourly_cost' => $this->when($request->user()?->isInternal(), $this->hourly_cost),
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'client_id' => $this->client_id,
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'ruc' => $this->client->ruc,
                'business_name' => $this->client->business_name,
            ] : null),
            'role' => $this->whenLoaded('roles', fn () => $this->primaryRole()),
            'permissions' => $this->whenLoaded('roles', fn () => $this->getAllPermissions()->pluck('name')->values()),
            'assigned_clients' => $this->whenLoaded('assignedClients', fn () => $this->assignedClients->map(fn ($c) => [
                'id' => $c->id, 'business_name' => $c->business_name, 'role' => $c->pivot->role,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
