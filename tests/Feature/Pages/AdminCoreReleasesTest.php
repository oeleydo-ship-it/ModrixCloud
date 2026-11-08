<?php

namespace Tests\Feature\Pages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Models\CoreRelease;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** The admin "Core releases" page: upload, publish, withdraw. */
class AdminCoreReleasesTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.storage' => sys_get_temp_dir().'/admin-core-'.uniqid()]);
    }

    public function test_admin_uploads_and_publishes_a_core_release()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/core', [
            'version' => '1.1.0',
            'notes' => 'First update',
            'min_php' => '8.3',
            'package' => $this->coreZip('1.1.0'),
        ])->assertRedirect('/admin/core')->assertSessionHasNoErrors();

        $release = CoreRelease::query()->where('version', '1.1.0')->firstOrFail();
        $this->assertSame(CoreRelease::DRAFT, $release->status);

        $this->actingAs($admin)->post("/admin/core/{$release->id}/publish")->assertRedirect('/admin/core');
        $this->assertSame(CoreRelease::PUBLISHED, $release->fresh()->status);

        $this->actingAs($admin)->get('/admin/core')->assertOk()->assertSee('1.1.0')->assertSee('Core releases');

        $this->actingAs($admin)->post("/admin/core/{$release->id}/withdraw")->assertRedirect('/admin/core');
        $this->assertSame(CoreRelease::WITHDRAWN, $release->fresh()->status);
    }

    public function test_customer_cannot_open_core_releases()
    {
        $this->get('/admin/core')->assertRedirect('/admin/login');
        $this->actingAs($this->customer())->get('/admin/core')->assertForbidden();
        $this->actingAs($this->customer())->post('/admin/core', [])->assertForbidden();
    }

    public function test_upload_error_is_shown()
    {
        $this->actingAs($this->admin())->from('/admin/core')->post('/admin/core', [
            'version' => '1.1.0',
            'min_php' => '8.3',
            'package' => $this->coreZip('1.0.9'),
        ])->assertRedirect('/admin/core')->assertSessionHasErrors('package');

        $this->assertSame(0, CoreRelease::query()->count());
    }
}
