<?php

namespace Tests\Feature;

use App\Support\Deployment;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(base_path('DEPLOY_ID'));
        @unlink(storage_path('framework/deploy-id'));
        parent::tearDown();
    }

    public function test_a_new_upload_clears_compiled_pages_once(): void
    {
        $compiled = config('view.compiled').'/stale-test-view.php';
        file_put_contents($compiled, 'old page');

        Deployment::refreshIfChanged(); // no DEPLOY_ID: nothing happens
        $this->assertFileExists($compiled);

        file_put_contents(base_path('DEPLOY_ID'), "20260930-abc\n");
        Deployment::refreshIfChanged();
        $this->assertFileDoesNotExist($compiled);

        file_put_contents($compiled, 'new page');
        Deployment::refreshIfChanged(); // same release: kept
        $this->assertFileExists($compiled);
        @unlink($compiled);
    }
}
