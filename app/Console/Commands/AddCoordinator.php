<?php

namespace App\Console\Commands;

use App\Models\Coordinator;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

class AddCoordinator extends Command
{
    protected $signature = 'coordinator:add
        {phone : Coordinator phone number}
        {name : Coordinator name}
        {--lga= : LGA covered (omit for state-wide)}
        {--email= : Email address}';

    protected $description = 'Register (or update) a coordinator who receives urgent incident SMS alerts';

    public function handle(): int
    {
        $coordinator = Coordinator::updateOrCreate(
            ['phone_number' => PhoneNumber::normalize($this->argument('phone'))],
            ['name' => $this->argument('name'), 'lga' => $this->option('lga'), 'email' => $this->option('email')],
        );

        $this->info("Saved coordinator {$coordinator->name} ({$coordinator->phone_number}) for "
            .($coordinator->lga ? "{$coordinator->lga} LGA" : 'all LGAs'));

        return self::SUCCESS;
    }
}
