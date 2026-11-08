<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Models\CoreRelease;
use Marketplace\Services\CoreReleaseException;
use Marketplace\Services\CoreReleases;
use Marketplace\Services\Signing;
use Marketplace\Services\SigningKey;
use Tests\Support\Fixtures;
use Tests\TestCase;
use ZipArchive;

class CoreReleasesTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.storage' => sys_get_temp_dir().'/core-releases-'.uniqid()]);
    }

    private function releases(): CoreReleases
    {
        return app(CoreReleases::class);
    }

    private function upload(string $version, string $php = '8.3'): CoreRelease
    {
        return $this->releases()->upload($this->coreZip($version)->getRealPath(), $version, null, $php);
    }

    public function test_upload_stores_a_draft(): void
    {
        $r = $this->releases()->upload($this->coreZip('1.1.0')->getRealPath(), '1.1.0', 'Notes', '8.3');

        $this->assertSame(CoreRelease::DRAFT, $r->status);
        $this->assertSame('Notes', $r->notes);
        $this->assertFileExists($this->releases()->path($r));
        $this->assertSame(hash_file('sha256', $this->releases()->path($r)), $r->sha256);
        $this->assertSame((int) filesize($this->releases()->path($r)), $r->size);
    }

    public function test_upload_refuses_wrong_declared_version(): void
    {
        $this->expectException(CoreReleaseException::class);
        $this->releases()->upload($this->coreZip('1.0.9')->getRealPath(), '1.1.0', null, '8.3');
    }

    public function test_upload_refuses_missing_vendor_autoload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('artisan', '<?php');
        $zip->addFromString('app/Core/Platform.php', "<?php const X=1; public const VERSION = '1.1.0';");
        $zip->close();

        $this->expectException(CoreReleaseException::class);
        $this->releases()->upload($path, '1.1.0', null, '8.3');
    }

    public function test_upload_refuses_duplicate_version(): void
    {
        $this->upload('1.1.0');

        $this->expectException(CoreReleaseException::class);
        $this->upload('1.1.0');
    }

    public function test_upload_refuses_invalid_semver(): void
    {
        $this->expectException(CoreReleaseException::class);
        $this->releases()->upload($this->coreZip('abc')->getRealPath(), 'abc', null, '8.3');
    }

    public function test_upload_refuses_versions_that_could_escape_storage(): void
    {
        foreach (['dev-x', 'dev-../../x', '1.0.0+../x', '1.0.0-a/b', '1.0.0-a\b', '1.0'] as $bad) {
            try {
                $this->releases()->upload($this->coreZip($bad)->getRealPath(), $bad, null, '8.3');
                $this->fail("Accepted {$bad}");
            } catch (CoreReleaseException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, CoreRelease::query()->count());
    }

    public function test_upload_refuses_invalid_min_php(): void
    {
        $this->expectException(CoreReleaseException::class);
        $this->releases()->upload($this->coreZip('1.1.0')->getRealPath(), '1.1.0', null, 'eight');
    }

    public function test_upload_refuses_unsafe_entries(): void
    {
        $this->expectException(CoreReleaseException::class);
        $this->releases()->upload($this->coreZip('1.1.0', ['../evil.php' => 'x'])->getRealPath(), '1.1.0', null, '8.3');
    }

    public function test_publish_signs_verifiable_metadata(): void
    {
        $r = $this->releases()->publish($this->upload('1.1.0'));

        $this->assertSame(CoreRelease::PUBLISHED, $r->status);
        $this->assertNotNull($r->published_at);
        $this->assertTrue(Signing::verify($r->signed_metadata, $r->signature, [app(SigningKey::class)->publicKey()]));
        $this->assertSame('core', $r->signed_metadata['kind']);
        $this->assertSame('8.3', $r->signed_metadata['min_php']);
        $this->assertSame($r->id, $r->signed_metadata['release_id']);
        $this->assertSame($r->sha256, $r->signed_metadata['sha256']);
    }

    public function test_payload_shape(): void
    {
        $r = $this->releases()->publish($this->upload('1.1.0'));
        $payload = $this->releases()->payload($r);

        $this->assertSame(['id', 'version', 'min_php', 'notes', 'published_at', 'verification'], array_keys($payload));
        $this->assertSame($r->signature, $payload['verification']['signature']);
    }

    public function test_newer_than_filters(): void
    {
        $a = $this->releases()->publish($this->upload('1.1.0', '8.3'));
        $b = $this->releases()->publish($this->upload('1.2.0', '9.0'));
        $this->upload('1.3.0');
        $w = $this->releases()->publish($this->upload('1.0.5'));
        $this->releases()->withdraw($w);

        $this->assertSame(CoreRelease::WITHDRAWN, $w->fresh()->status);
        $this->assertNotNull($w->fresh()->published_at);
        $this->assertSame(['1.1.0'], $this->releases()->newerThan('1.0.0', '8.4.1')->pluck('version')->all());
        $this->assertSame(['1.2.0', '1.1.0'], $this->releases()->newerThan(null, null)->pluck('version')->all());
    }

    public function test_upload_refuses_database_files(): void
    {
        foreach (['database/x.sqlite', 'database/database.sqlite3', 'storage/app.db', 'database/x.sqlite-journal', 'database/x.sqlite-wal', 'database/x.sqlite-shm'] as $i => $name) {
            try {
                $this->releases()->upload($this->coreZip('1.1.'.$i, [$name => 'SQLite format 3'])->getRealPath(), '1.1.'.$i, null, '8.3');
                $this->fail("Accepted {$name}");
            } catch (CoreReleaseException $e) {
                $this->assertStringContainsString('database file', $e->getMessage());
            }
        }

        $this->assertSame(0, CoreRelease::query()->count());
    }

    public function test_publish_rechecks_the_stored_file(): void
    {
        $r = $this->upload('1.1.0');
        $zip = new ZipArchive;
        $zip->open($this->releases()->path($r));
        $zip->addFromString('database/database.sqlite', 'SQLite format 3');
        $zip->close();

        try {
            $this->releases()->publish($r);
            $this->fail('Published a tampered file');
        } catch (CoreReleaseException $e) {
            $this->assertStringContainsString('database file', $e->getMessage());
        }

        $this->assertSame(CoreRelease::DRAFT, $r->fresh()->status);
        $this->assertNull($r->fresh()->signature);
    }

    public function test_only_drafts_can_be_published(): void
    {
        $r = $this->releases()->publish($this->upload('1.1.0'));
        $signature = $r->signature;

        try {
            $this->releases()->publish($r);
            $this->fail('Published twice');
        } catch (CoreReleaseException $e) {
            $this->assertSame('Only draft releases can be published.', $e->getMessage());
        }

        $this->releases()->withdraw($r);

        try {
            $this->releases()->publish($r->fresh());
            $this->fail('Re-published a withdrawn release');
        } catch (CoreReleaseException $e) {
            $this->assertSame('Only draft releases can be published.', $e->getMessage());
        }

        $this->assertSame(CoreRelease::WITHDRAWN, $r->fresh()->status);
        $this->assertSame($signature, $r->fresh()->signature);
    }

    public function test_withdraw_only_from_draft_or_published(): void
    {
        $draft = $this->upload('1.1.0');
        $this->releases()->withdraw($draft);
        $this->assertSame(CoreRelease::WITHDRAWN, $draft->fresh()->status);

        $this->expectException(CoreReleaseException::class);
        $this->releases()->withdraw($draft->fresh());
    }
}
