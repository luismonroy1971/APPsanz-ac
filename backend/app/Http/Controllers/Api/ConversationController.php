<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * C2 / S2: chat de consultas entre el cliente y SANZ & AC.
 */
class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $q = Conversation::visibleTo($user)
            ->with('client:id,business_name', 'creator:id,name', 'assignee:id,name', 'latestMessage')
            ->withCount(['messages as unread_count' => fn ($q) => $q->whereNull('read_at')->where('from_client', $user->isInternal())])
            ->forClient($request->integer('client_id') ?: null)
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->status)))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $user->id))
            ->when($request->filled('search'), fn ($q) => $q->where('subject', 'like', '%'.$request->search.'%'))
            ->orderByRaw("FIELD(status, 'abierta', 'en_seguimiento', 'respondida', 'cerrada')")
            ->orderByDesc('last_message_at');

        $stats = Conversation::visibleTo($user)->forClient($request->integer('client_id') ?: null)
            ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return response()->json($q->paginate($this->perPage($request))->toArray() + ['stats' => $stats]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:contable,tributario,rrhh,tesoreria,general'],
            'priority' => ['nullable', 'in:baja,media,alta'],
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);
        $user = $this->user($request);
        $clientId = $this->resolveClientId($request);

        $conversation = DB::transaction(function () use ($data, $user, $clientId, $request) {
            $assignee = \App\Models\Client::find($clientId)->team()->wherePivot('role', 'analista')->value('users.id');
            $conv = Conversation::create([
                'client_id' => $clientId,
                'created_by' => $user->id,
                'assigned_to' => $assignee,
                'subject' => $data['subject'],
                'category' => $data['category'],
                'priority' => $data['priority'] ?? 'media',
                'status' => $user->isExternal() ? 'abierta' : 'respondida',
                'last_message_at' => now(),
                'first_response_at' => $user->isExternal() ? null : now(),
            ]);
            $this->addMessage($conv, $request, $data['body']);

            return $conv;
        });

        return response()->json(['data' => $conversation->load('messages.user:id,name,type'), 'message' => 'Consulta registrada.'], 201);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureClientAccess($request, $conversation->client_id);
        $user = $this->user($request);

        // Marcar como leídos los mensajes de la contraparte
        $conversation->messages()->whereNull('read_at')->where('from_client', $user->isInternal())->update(['read_at' => now()]);

        return response()->json(['data' => $conversation->load('client:id,business_name', 'creator:id,name', 'assignee:id,name', 'messages.user:id,name,type')]);
    }

    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureClientAccess($request, $conversation->client_id);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);
        abort_if($conversation->status === 'cerrada' && $this->user($request)->isExternal(), 422, 'La consulta está cerrada. Abra una nueva consulta.');

        $message = DB::transaction(fn () => $this->addMessage($conversation, $request, $data['body']));

        return response()->json(['data' => $message->load('user:id,name,type'), 'message' => 'Mensaje enviado.'], 201);
    }

    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureClientAccess($request, $conversation->client_id);
        $user = $this->user($request);
        $data = $request->validate([
            'status' => ['nullable', 'in:abierta,respondida,en_seguimiento,cerrada'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'priority' => ['nullable', 'in:baja,media,alta'],
        ]);
        if ($user->isExternal()) {
            // El cliente solo puede cerrar o reabrir
            $data = array_intersect_key($data, ['status' => true]);
            abort_if(isset($data['status']) && ! in_array($data['status'], ['cerrada', 'abierta'], true), 403);
        }
        if (($data['status'] ?? null) === 'cerrada') {
            $data['closed_at'] = now();
        }
        $conversation->update($data);

        return response()->json(['data' => $conversation->fresh(['assignee:id,name']), 'message' => 'Consulta actualizada.']);
    }

    public function attachment(Request $request, Message $message): StreamedResponse
    {
        $this->ensureClientAccess($request, $message->conversation->client_id);

        return $this->downloadFile($message->attachment_path, $message->attachment_name);
    }

    private function addMessage(Conversation $conv, Request $request, string $body): Message
    {
        $user = $this->user($request);
        $attachment = $request->hasFile('attachment')
            ? $this->storeFile($request->file('attachment'), "chat/{$conv->client_id}")
            : null;

        $message = $conv->messages()->create([
            'user_id' => $user->id,
            'from_client' => $user->isExternal(),
            'body' => $body,
            'attachment_path' => $attachment['path'] ?? null,
            'attachment_name' => $attachment['original_name'] ?? null,
        ]);

        $updates = ['last_message_at' => now()];
        if ($user->isExternal()) {
            $updates['status'] = $conv->status === 'cerrada' ? 'abierta' : ($conv->first_response_at ? 'en_seguimiento' : 'abierta');
        } else {
            $updates['status'] = 'respondida';
            if (! $conv->first_response_at && $conv->messages()->where('from_client', true)->exists()) {
                $updates['first_response_at'] = now();
            }
            if (! $conv->assigned_to) {
                $updates['assigned_to'] = $user->id;
            }
        }
        $conv->update($updates);

        return $message;
    }
}
