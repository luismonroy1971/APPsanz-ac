<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\FinancialReport;
use App\Models\Message;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Voucher;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $user->isExternal() ? $this->client($request) : $this->internal($request)]);
    }

    private function client(Request $request): array
    {
        $user = $this->user($request);
        $clientId = $user->client_id;
        $weekStart = CarbonImmutable::now()->startOfWeek();

        $docs = Document::where('client_id', $clientId)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return [
            'type' => 'client',
            'client' => Client::find($clientId, ['id', 'ruc', 'business_name', 'contract_end']),
            'documents' => [
                'by_status' => $docs,
                'total' => $docs->sum(),
                'returned' => Document::where('client_id', $clientId)->where('status', 'devuelto')
                    ->whereDoesntHave('resubmissions')->latest('returned_at')->limit(5)
                    ->get(['id', 'original_name', 'return_reason', 'returned_at']),
                'recent' => Document::where('client_id', $clientId)->latest()->limit(6)
                    ->get(['id', 'original_name', 'category', 'status', 'created_at']),
            ],
            'conversations' => [
                'open' => Conversation::where('client_id', $clientId)->whereIn('status', ['abierta', 'en_seguimiento', 'respondida'])->count(),
                'unread' => Message::whereHas('conversation', fn ($q) => $q->where('client_id', $clientId))
                    ->where('from_client', false)->whereNull('read_at')->count(),
                'recent' => Conversation::where('client_id', $clientId)->latest('last_message_at')->limit(5)
                    ->get(['id', 'subject', 'status', 'last_message_at']),
            ],
            'reports' => FinancialReport::where('client_id', $clientId)->where('published', true)
                ->latest('published_at')->limit(5)->get(['id', 'report_code', 'title', 'published_at']),
            'accesses_week' => AccessLog::where('client_id', $clientId)->where('event', 'login')
                ->where('created_at', '>=', $weekStart)->count(),
            'month' => [
                'purchases' => (float) Voucher::where('client_id', $clientId)->where('operation', 'compra')
                    ->where('status', '!=', 'anulado')
                    ->whereBetween('issue_date', [now()->startOfMonth(), now()->endOfMonth()])->sum(DB::raw('total * exchange_rate')),
                'sales' => (float) Voucher::where('client_id', $clientId)->where('operation', 'venta')
                    ->where('status', '!=', 'anulado')
                    ->whereBetween('issue_date', [now()->startOfMonth(), now()->endOfMonth()])->sum(DB::raw('total * exchange_rate')),
            ],
        ];
    }

    private function internal(Request $request): array
    {
        $user = $this->user($request);
        $ids = $user->accessibleClientIds();
        $scope = fn ($q, $col = 'client_id') => $ids === null ? $q : $q->whereIn($col, $ids);
        $today = CarbonImmutable::today();
        $weekStart = $today->startOfWeek();
        $weekEnd = $today->endOfWeek();
        $from14 = $today->subDays(13);

        $pendingDocs = $scope(Document::query())->whereIn('status', ['ingresado', 'en_proceso', 'reingresado', 'observado']);
        $docsPerDay = $scope(Document::query())->where('created_at', '>=', $from14)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as total')->groupBy('d')->pluck('total', 'd');
        $processedPerDay = $scope(Document::query())->where('processed_at', '>=', $from14)
            ->selectRaw('DATE(processed_at) as d, COUNT(*) as total')->groupBy('d')->pluck('total', 'd');

        $series = [];
        foreach (CarbonPeriod::create($from14, $today) as $day) {
            $d = $day->toDateString();
            $series[] = ['label' => $day->format('d/m'), 'Ingresados' => (int) ($docsPerDay[$d] ?? 0), 'Procesados' => (int) ($processedPerDay[$d] ?? 0)];
        }

        $tasks = $scope(Task::query())->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        $hoursByClient = $scope(TimeEntry::query())->whereBetween('date', [$weekStart, $weekEnd])
            ->join('clients', 'clients.id', '=', 'time_entries.client_id')
            ->selectRaw('clients.business_name as label, SUM(hours) as Horas')->groupBy('clients.business_name')
            ->orderByDesc('Horas')->limit(8)->get()->map(fn ($r) => ['label' => $r->label, 'Horas' => round((float) $r->Horas, 2)]);

        $pendingByClient = $scope(Document::query(), 'documents.client_id')
            ->whereIn('documents.status', ['ingresado', 'en_proceso', 'reingresado', 'observado'])
            ->join('clients', 'clients.id', '=', 'documents.client_id')
            ->selectRaw('clients.id, clients.business_name, COUNT(*) as total')->groupBy('clients.id', 'clients.business_name')
            ->orderByDesc('total')->limit(6)->get();

        return [
            'type' => 'internal',
            'role' => $user->primaryRole(),
            'kpis' => [
                'pending_documents' => (clone $pendingDocs)->count(),
                'observed_documents' => $scope(Document::query())->where('status', 'observado')->count(),
                'unanswered_chats' => $scope(Conversation::query())->whereIn('status', ['abierta', 'en_seguimiento'])->count(),
                'overdue_tasks' => $scope(Task::query())->where('status', '!=', 'completado')->whereDate('due_date', '<', $today)->count(),
                'tasks_this_week' => $scope(Task::query())->where('status', '!=', 'completado')->whereBetween('due_date', [$weekStart, $weekEnd])->count(),
                'my_hours_week' => round((float) TimeEntry::where('user_id', $user->id)->whereBetween('date', [$weekStart, $weekEnd])->sum('hours'), 2),
                'team_hours_week' => round((float) $scope(TimeEntry::query())->whereBetween('date', [$weekStart, $weekEnd])->sum('hours'), 2),
                'vouchers_month' => $scope(Voucher::query())->whereBetween('created_at', [$today->startOfMonth(), $today->endOfDay()])->count(),
                'clients' => $ids === null ? Client::where('is_active', true)->count() : count($ids),
                'client_accesses_week' => $scope(AccessLog::query())->whereNotNull('client_id')->where('event', 'login')
                    ->where('created_at', '>=', $weekStart)->count(),
                'reports_to_review' => $scope(FinancialReport::query())->where('status', 'borrador')->count(),
            ],
            'documents_series' => $series,
            'tasks_by_status' => $tasks,
            'hours_by_client' => $hoursByClient,
            'pending_by_client' => $pendingByClient,
            'my_tasks' => $scope(Task::query())->with('client:id,business_name')->where('assigned_to', $user->id)
                ->where('status', '!=', 'completado')->orderBy('due_date')->limit(6)
                ->get(['id', 'client_id', 'title', 'status', 'due_date', 'area', 'deliverable']),
            'latest_chats' => $scope(Conversation::query())->with('client:id,business_name')
                ->whereIn('status', ['abierta', 'en_seguimiento'])->orderBy('last_message_at')->limit(5)
                ->get(['id', 'client_id', 'subject', 'status', 'last_message_at', 'priority']),
        ];
    }
}
