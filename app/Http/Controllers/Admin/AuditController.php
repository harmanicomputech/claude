<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Audit;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.audit', [
            'filters' => $filters,
            'entries' => $this->query($filters)->paginate(100)->withQueryString(),
            'actions' => AuditLog::distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $timezone = config('election.timezone');

        Audit::record('audit.exported', 'Exported the audit log');

        $rows = function () use ($filters, $timezone) {
            foreach ($this->query($filters)->lazy(1000) as $entry) {
                yield [
                    $entry->created_at->timezone($timezone)->format('Y-m-d H:i:s'),
                    $entry->user_name,
                    $entry->action,
                    $entry->description,
                    trim($entry->subject_type.' '.$entry->subject_id),
                    $entry->details ? json_encode($entry->details) : '',
                    $entry->ip_address,
                ];
            }
        };

        return CsvExport::download(CsvExport::filename('audit-log'), ['Time', 'Who', 'Action', 'Description', 'Subject', 'Details', 'IP address'], $rows());
    }

    /**
     * @return array{q: string, action: ?string}
     */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q')),
            'action' => $request->query('action') ?: null,
        ];
    }

    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->when($filters['action'], fn (Builder $query, $action) => $query->where('action', $action))
            ->when($filters['q'] !== '', fn (Builder $query) => $query->where(fn ($query) => $query
                ->where('description', 'like', "%{$filters['q']}%")
                ->orWhere('user_name', 'like', "%{$filters['q']}%")
                ->orWhere('subject_id', 'like', "%{$filters['q']}%")))
            ->latest('created_at')
            ->latest('id');
    }
}
