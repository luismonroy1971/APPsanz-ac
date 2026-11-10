<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InternalCommunication;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** R11: instrucciones / directivas / comunicaciones internas. */
class InternalCommunicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $managers = $user->hasAnyRole(['socio', 'supervisor']);

        $q = InternalCommunication::with('author:id,name', 'client:id,business_name')
            ->withCount(['recipients', 'recipients as read_count' => fn ($q) => $q->whereNotNull('communication_recipients.read_at')])
            ->with(['recipients' => fn ($q) => $q->where('users.id', $user->id)->select('users.id')])
            ->when(! $managers || $request->boolean('inbox'), fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('recipients', fn ($r) => $r->where('users.id', $user->id))
                ->orWhere('author_id', $user->id)))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->latest();

        $page = $q->paginate($this->perPage($request));
        $page->getCollection()->transform(function ($c) {
            $mine = $c->recipients->first();
            $c->my_read_at = $mine?->pivot->read_at;
            $c->my_acknowledged_at = $mine?->pivot->acknowledged_at;
            unset($c->recipients);

            return $c;
        });

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasAnyRole(['socio', 'supervisor']), 403, 'Solo socios y supervisores emiten comunicaciones.');
        $data = $request->validate([
            'type' => ['required', 'in:instruccion,directiva,comunicado'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'priority' => ['nullable', 'in:baja,media,alta'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'recipients' => ['nullable', 'array'],
            'recipients.*' => ['integer', 'exists:users,id'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);
        $file = $request->hasFile('attachment') ? $this->storeFile($request->file('attachment'), 'communications') : [];

        $comm = DB::transaction(function () use ($data, $user, $file) {
            $comm = InternalCommunication::create([
                'author_id' => $user->id,
                'client_id' => $data['client_id'] ?? null,
                'type' => $data['type'],
                'title' => $data['title'],
                'body' => $data['body'],
                'priority' => $data['priority'] ?? 'media',
                'attachment_path' => $file['path'] ?? null,
                'attachment_name' => $file['original_name'] ?? null,
                'published_at' => now(),
            ]);
            $recipients = $data['recipients'] ?? User::where('type', 'interno')->where('is_active', true)
                ->where('id', '!=', $user->id)->pluck('id')->all();
            $comm->recipients()->sync($recipients);

            return $comm;
        });

        return response()->json(['data' => $comm, 'message' => 'Comunicación publicada.'], 201);
    }

    public function show(Request $request, InternalCommunication $communication): JsonResponse
    {
        $user = $this->user($request);
        $isRecipient = $communication->recipients()->where('users.id', $user->id)->exists();
        abort_unless($isRecipient || $communication->author_id === $user->id || $user->hasAnyRole(['socio', 'supervisor']), 403);

        if ($isRecipient) {
            $communication->recipients()->wherePivotNull('read_at')->updateExistingPivot($user->id, ['read_at' => now()]);
        }

        return response()->json(['data' => $communication->load('author:id,name', 'client:id,business_name', 'recipients:id,name')]);
    }

    public function acknowledge(Request $request, InternalCommunication $communication): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($communication->recipients()->where('users.id', $user->id)->exists(), 403);
        $communication->recipients()->updateExistingPivot($user->id, ['acknowledged_at' => now(), 'read_at' => now()]);

        return response()->json(['message' => 'Lectura confirmada.']);
    }

    public function attachment(Request $request, InternalCommunication $communication): StreamedResponse
    {
        return $this->downloadFile($communication->attachment_path, $communication->attachment_name);
    }
}
