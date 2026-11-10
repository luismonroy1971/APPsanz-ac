<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class Controller
{
    protected function user(Request $request): User
    {
        return $request->user();
    }

    /** Aborta con 403 si el usuario no puede acceder al cliente. */
    protected function ensureClientAccess(Request $request, int $clientId): Client
    {
        abort_unless($this->user($request)->canAccessClient($clientId), 403, 'No tiene acceso a este cliente.');

        return Client::findOrFail($clientId);
    }

    /** Para usuarios externos, el cliente siempre es el suyo; los internos lo indican. */
    protected function resolveClientId(Request $request, bool $required = true): ?int
    {
        $user = $this->user($request);
        if ($user->isExternal()) {
            return $user->client_id;
        }
        $id = $request->integer('client_id') ?: null;
        abort_if($required && ! $id, 422, 'Debe indicar el cliente.');
        if ($id) {
            $this->ensureClientAccess($request, $id);
        }

        return $id;
    }

    protected function ensureInternal(Request $request): void
    {
        abort_unless($this->user($request)->isInternal(), 403, 'Acción reservada al personal de SANZ & AC.');
    }

    protected function storeFile(UploadedFile $file, string $folder): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs($folder, Str::uuid().'.'.$ext, 'local');

        return [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'extension' => $ext,
            'size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    protected function downloadFile(?string $path, ?string $name): StreamedResponse
    {
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Archivo no encontrado.');

        return Storage::disk('local')->download($path, $name ?: basename($path));
    }

    protected function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 5), 100);
    }
}
