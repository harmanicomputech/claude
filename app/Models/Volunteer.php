<?php

namespace App\Models;

use App\Enums\VolunteerRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A "How can you help?" sign-up (USSD). One per phone number: signing up
 * again updates it.
 */
#[Fillable(['reference', 'phone_number', 'contact_phone', 'name', 'lga', 'ward', 'roles', 'skills', 'other', 'channel', 'is_agent'])]
class Volunteer extends Model
{
    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'skills' => 'array',
            'is_agent' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function roleLabels(): array
    {
        return array_values(array_filter(array_map(fn (string $role) => VolunteerRole::tryFrom($role)?->label(), $this->roles ?? [])));
    }

    /**
     * @return list<string>
     */
    public function skillLabels(): array
    {
        return array_values(array_map(fn (string $skill) => VolunteerRole::SKILLS[$skill] ?? $skill, $this->skills ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'reference' => $this->reference,
            'name' => $this->name,
            'phone_number' => $this->phone_number,
            'contact_phone' => $this->contact_phone,
            'lga' => $this->lga,
            'ward' => $this->ward,
            'roles' => $this->roles ?? [],
            'role_labels' => $this->roleLabels(),
            'skills' => $this->skills ?? [],
            'skill_labels' => $this->skillLabels(),
            'other' => $this->other,
            'is_agent' => $this->is_agent,
            'channel' => $this->channel ?? 'ussd',
            'registered_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
