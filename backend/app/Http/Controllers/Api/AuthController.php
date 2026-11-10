<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AccessLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'portal' => ['nullable', 'in:externo,interno'],
        ]);

        $user = User::with('client')->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $this->log($request, $user, 'failed');
            }
            throw ValidationException::withMessages(['email' => 'Las credenciales no son correctas.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Su usuario se encuentra desactivado. Contacte a SANZ & AC.']);
        }

        if ($user->isExternal()) {
            $client = $user->client;
            if (! $client || ! $client->is_active) {
                throw ValidationException::withMessages(['email' => 'El acceso de su empresa no está habilitado.']);
            }
            if ($client->contract_end && $client->contract_end->isPast()) {
                throw ValidationException::withMessages(['email' => 'El contrato de servicios de su empresa ha vencido.']);
            }
        }

        if (! empty($data['portal']) && $data['portal'] !== $user->type) {
            throw ValidationException::withMessages([
                'email' => $user->isExternal()
                    ? 'Este usuario corresponde al acceso externo (clientes).'
                    : 'Este usuario corresponde al acceso interno (SANZ & AC).',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->log($request, $user, 'login');

        $token = $user->createToken('spa', ['*'], now()->addHours(12))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('client', 'roles', 'permissions')),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('client', 'roles', 'permissions'));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->log($request, $user, 'logout');
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $request->user()->update(['password' => $data['password']]);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    private function log(Request $request, User $user, string $event): void
    {
        AccessLog::create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
