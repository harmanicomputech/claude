<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person who can log in to the admin console.
 */
#[Fillable(['name', 'email', 'password', 'role', 'lga', 'ward'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * "Abakaliki LGA", "Abakpa ward, Abakaliki", or what an admin / unassigned account sees.
     */
    public function areaLabel(): string
    {
        return match (true) {
            $this->isAdmin() => 'All LGAs',
            ! $this->lga => 'No area yet: sees nothing',
            (bool) $this->ward => "{$this->ward} ward, {$this->lga}",
            default => "{$this->lga} LGA",
        };
    }
}
