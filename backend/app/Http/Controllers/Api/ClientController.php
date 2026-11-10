<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Sunat\RucValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $q = Client::visibleTo($user)
            ->withCount([
                'users',
                'documents as pending_documents_count' => fn ($q) => $q->whereIn('status', ['ingresado', 'en_proceso', 'reingresado', 'observado']),
                'conversations as open_conversations_count' => fn ($q) => $q->whereIn('status', ['abierta', 'en_seguimiento']),
            ])
            ->with('team:id,name')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('business_name', 'like', '%'.$request->search.'%')
                ->orWhere('ruc', 'like', '%'.$request->search.'%')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('business_name');

        if ($request->boolean('all')) {
            return response()->json(['data' => $q->get(['id', 'ruc', 'business_name', 'trade_name', 'is_active'])]);
        }

        return response()->json($q->paginate($this->perPage($request)));
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $this->ensureClientAccess($request, $client->id);
        $client->load(['team:id,name,email,position', 'users' => fn ($q) => $q->with('permissions:id,name')->orderBy('name')]);

        return response()->json(['data' => $client]);
    }

    public function store(Request $request): JsonResponse
    {
        $client = Client::create($this->validated($request));

        return response()->json(['data' => $client, 'message' => 'Cliente registrado.'], 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $client->update($this->validated($request, $client));

        return response()->json(['data' => $client, 'message' => 'Cliente actualizado.']);
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->update(['is_active' => false]);
        $client->delete();

        return response()->json(['message' => 'Cliente dado de baja.']);
    }

    /** Asigna el equipo interno (analistas / supervisor / socio) al cliente. */
    public function assignTeam(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'members' => ['array'],
            'members.*.user_id' => ['required', Rule::exists('users', 'id')->where('type', 'interno')],
            'members.*.role' => ['required', 'in:analista,supervisor,socio'],
        ]);

        $sync = [];
        foreach ($data['members'] ?? [] as $m) {
            $sync[$m['user_id']] = ['role' => $m['role']];
        }
        $client->team()->sync($sync);

        return response()->json(['data' => $client->load('team:id,name,email,position'), 'message' => 'Equipo asignado.']);
    }

    private function validated(Request $request, ?Client $client = null): array
    {
        $data = $request->validate([
            'ruc' => ['required', 'digits:11', Rule::unique('clients', 'ruc')->ignore($client?->id)],
            'business_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_regime' => ['required', 'in:general,mype,especial,rus'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contract_number' => ['nullable', 'string', 'max:50'],
            'contract_start' => ['nullable', 'date'],
            'contract_end' => ['nullable', 'date', 'after_or_equal:contract_start'],
            'max_external_users' => ['nullable', 'integer', 'min:1', 'max:100'],
            'access_notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        abort_unless(RucValidator::isValid($data['ruc']), 422, 'El RUC ingresado no es válido (dígito verificador).');

        return $data;
    }
}
