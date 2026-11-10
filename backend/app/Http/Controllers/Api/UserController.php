<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public const CLIENT_PERMISSIONS = ['documentos.subir', 'consultas.usar', 'informes.ver'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = User::with('client', 'roles', 'permissions', 'assignedClients:id,business_name')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->filled('role'), fn ($q) => $q->role($request->role))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->search.'%')
                ->orWhere('email', 'like', '%'.$request->search.'%')))
            ->orderBy('type')->orderBy('name');

        return UserResource::collection($request->boolean('all') ? $q->get() : $q->paginate($this->perPage($request)));
    }

    /** Lista simple del personal interno (para asignaciones). */
    public function staff(): JsonResponse
    {
        return response()->json(['data' => User::where('type', 'interno')->where('is_active', true)
            ->with('roles:id,name')->orderBy('name')->get(['id', 'name', 'email', 'position'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'position' => $u->position, 'role' => $u->primaryRole()])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if ($data['type'] === 'externo') {
            $client = Client::findOrFail($data['client_id']);
            abort_if($client->users()->where('is_active', true)->count() >= $client->max_external_users, 422,
                "El cliente alcanzó el máximo de {$client->max_external_users} usuarios acordado en el contrato.");
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create($data);
            $this->syncAccess($user, $data);

            return $user;
        });

        return response()->json(['data' => new UserResource($user->load('client', 'roles', 'permissions')), 'message' => 'Usuario creado.'], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);
        if (empty($data['password'])) {
            unset($data['password']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->update($data);
            $this->syncAccess($user, $data);
            if (! $user->is_active) {
                $user->tokens()->delete();
            }
        });

        return response()->json(['data' => new UserResource($user->fresh()->load('client', 'roles', 'permissions')), 'message' => 'Usuario actualizado.']);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'No puede desactivar su propio usuario.');
        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Usuario desactivado.']);
    }

    private function syncAccess(User $user, array $data): void
    {
        if ($user->type === 'externo') {
            $user->syncRoles(['cliente']);
            $user->syncPermissions($data['permissions'] ?? self::CLIENT_PERMISSIONS);
        } else {
            $user->syncRoles([$data['role']]);
            $user->syncPermissions([]);
        }
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user?->id)],
            'type' => ['required', 'in:interno,externo'],
            'role' => ['required_if:type,interno', 'nullable', 'in:socio,supervisor,analista'],
            'client_id' => ['required_if:type,externo', 'nullable', 'exists:clients,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'hourly_cost' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['in:'.implode(',', self::CLIENT_PERMISSIONS)],
            'password' => [$user ? 'nullable' : 'required', Password::min(8)->letters()->numbers()],
        ]);
    }
}
