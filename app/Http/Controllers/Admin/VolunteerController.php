<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VolunteerRole;
use App\Http\Controllers\Controller;
use App\Models\Volunteer;
use App\Support\Audit;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "How can you help?" sign-ups from USSD (the web app has a fuller page).
 */
class VolunteerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.volunteers', [
            'filters' => $filters,
            'volunteers' => $this->query($filters)->paginate(50)->withQueryString(),
            'total' => Volunteer::query()->count(),
            'roles' => VolunteerRole::cases(),
            'lgas' => Volunteer::query()->distinct()->orderBy('lga')->pluck('lga'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        Audit::record('volunteer.exported', 'Exported volunteers', details: array_filter($filters));
        $timezone = config('election.timezone');

        $rows = function () use ($filters, $timezone) {
            foreach ($this->query($filters)->lazy(500) as $volunteer) {
                yield [
                    $volunteer->reference,
                    $volunteer->name,
                    $volunteer->contact_phone,
                    $volunteer->phone_number,
                    $volunteer->lga,
                    $volunteer->ward,
                    implode('; ', $volunteer->roleLabels()),
                    implode('; ', $volunteer->skillLabels()),
                    $volunteer->other,
                    $volunteer->is_agent ? 'yes' : 'no',
                    $volunteer->created_at->timezone($timezone)->format('Y-m-d H:i'),
                    $volunteer->updated_at->timezone($timezone)->format('Y-m-d H:i'),
                ];
            }
        };

        return CsvExport::download(CsvExport::filename('volunteers'), [
            'Reference', 'Name', 'Contact number', 'Dialled from', 'LGA', 'Ward', 'How they can help', 'Professional skills', 'Anything else', 'Agent', 'Signed up', 'Updated',
        ], $rows());
    }

    /**
     * @return array{q: string, lga: ?string, role: ?string}
     */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q')),
            'lga' => $request->query('lga') ?: null,
            'role' => VolunteerRole::tryFrom((string) $request->query('role'))?->value,
        ];
    }

    private function query(array $filters): Builder
    {
        return Volunteer::query()
            ->when($filters['lga'], fn (Builder $query, $lga) => $query->where('lga', $lga))
            ->when($filters['role'], fn (Builder $query, $role) => $query->whereJsonContains('roles', $role))
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $term = $filters['q'];
                $digits = preg_replace('/\D/', '', $term);
                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', '%'.strtoupper($term).'%')
                    ->orWhere('ward', 'like', "%{$term}%")
                    ->when($digits !== '', fn ($query) => $query->orWhere('contact_phone', 'like', "%{$digits}%")->orWhere('phone_number', 'like', "%{$digits}%")));
            })
            ->latest('updated_at')
            ->latest('id');
    }
}
