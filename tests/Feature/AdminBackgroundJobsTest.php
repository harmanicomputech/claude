<?php

namespace Tests\Feature;

use App\Jobs\SendSms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBackgroundJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A real database queue, as on the production host.
        config([
            'queue.default' => 'database',
            'election.admin_password' => 'correct-horse',
            'services.africastalking.api_key' => null, // SMS are only logged
        ]);
    }

    public function test_jobs_can_be_run_from_the_console(): void
    {
        SendSms::dispatch('+2348011111111', 'Hello');
        $this->assertSame(1, DB::table('jobs')->count());

        $admin = $this->withSession(['admin.authenticated' => true]);

        $admin->get('/admin')->assertSee('1 waiting')->assertSee('Run background jobs now');

        $admin->post('/admin/system/jobs/run')->assertSessionHas('status', 'Background jobs processed');

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_failures_are_shown_and_can_be_retried(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Mail\\IncidentReported', 'job' => 'x', 'data' => []]),
            'exception' => "Symfony\\Component\\Mailer\\Exception\\TransportException: Failed to authenticate on SMTP server\n#0 trace line",
            'failed_at' => now(),
        ]);

        $admin = $this->withSession(['admin.authenticated' => true]);

        $admin->get('/admin')
            ->assertSee('1 failed')
            ->assertSee('IncidentReported')
            ->assertSee('Failed to authenticate on SMTP server')
            ->assertDontSee('#0 trace line')
            ->assertSee('Retry failed jobs');

        $admin->post('/admin/system/jobs/retry')->assertSessionHas('status');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }
}
