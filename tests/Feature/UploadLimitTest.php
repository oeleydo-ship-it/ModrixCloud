<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Models\User;
use Marketplace\Services\Uploads;
use Tests\TestCase;

/** PHP's upload limit is shown on the core page and explained when exceeded. */
class UploadLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_ini_sizes_are_read_as_bytes()
    {
        $this->assertSame(8 * 1024 * 1024, Uploads::iniBytes('8M'));
        $this->assertSame(1024 * 1024 * 1024, Uploads::iniBytes('1G'));
        $this->assertSame(512 * 1024, Uploads::iniBytes('512K'));
        $this->assertSame(1000, Uploads::iniBytes('1000'));
    }

    public function test_an_upload_over_the_server_limit_explains_how_to_raise_it()
    {
        $this->call('POST', '/admin/core', [], [], [], ['CONTENT_LENGTH' => (string) (Uploads::iniBytes((string) ini_get('post_max_size')) + 1)])
            ->assertStatus(413)
            ->assertSee('The file is too large for this server')
            ->assertSee('upload_max_filesize');
    }

    public function test_the_core_page_shows_the_server_limit()
    {
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password-1']);
        $admin->forceFill(['is_admin' => true])->save();

        $label = Uploads::serverLimitLabel();
        $response = $this->actingAs($admin)->get('/admin/core')->assertOk();

        if ($label !== null) {
            $response->assertSee('This server accepts uploads up to '.$label);
        }
    }
}
