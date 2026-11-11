<?php

namespace Tests\Feature\Pages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Marketplace\Models\Package;
use Marketplace\Models\Submission;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Publishers submit packages and updates; admins approve or reject them. */
class PublishTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    private function submit(array $overrides = [], ?UploadedFile $zip = null)
    {
        return $this->post('/submit', array_merge([
            'name' => 'Acme Reviews', 'identifier' => 'acme/reviews', 'type' => 'module', 'version' => '1.0.0',
            'platform_version' => '^1.0', 'author' => 'Acme', 'license_name' => 'MIT', 'summary' => 'Star ratings',
            'tier' => 'free', 'price' => '0', 'demo_url' => 'https://demo.example.com',
            'package' => $zip ?? $this->packageZip('acme/reviews', '1.0.0'),
        ], $overrides));
    }

    public function test_the_update_page_lists_own_and_marketplace_packages_in_a_dropdown()
    {
        $customer = $this->customer();
        $other = $this->customer();
        $this->package(['identifier' => 'modrixcommerce/razorpay', 'slug' => 'razorpay', 'name' => 'Razorpay']);
        $this->package(['identifier' => 'acme/mine', 'slug' => 'acme-mine', 'name' => 'Mine', 'publisher_id' => $customer->id]);
        $this->package(['identifier' => 'acme/theirs', 'slug' => 'acme-theirs', 'name' => 'Theirs', 'publisher_id' => $other->id]);

        $this->actingAs($customer)->get('/submit/update')
            ->assertOk()
            ->assertSee('<select name="identifier"', false)
            ->assertSee('modrixcommerce/razorpay')
            ->assertSee('acme/mine')
            ->assertDontSee('acme/theirs')
            ->assertDontSee('placeholder="vendor/package"', false);
    }

    public function test_updating_an_unknown_package_says_to_submit_it_first()
    {
        $this->actingAs($this->customer());

        $this->post('/submit/update', ['identifier' => 'modrixcommerce/missing', 'version' => '2.0.0', 'changelog' => 'New', 'package' => $this->packageZip('modrixcommerce/missing', '2.0.0')])
            ->assertSessionHasErrors(['identifier' => 'That package is not in the marketplace yet. Use Submit a package to add it first.']);
    }

    public function test_submitting_needs_sign_in()
    {
        $this->get('/submit')->assertRedirect('/account/login?next=%2Fsubmit');
    }

    public function test_a_manifest_that_does_not_match_is_refused()
    {
        $this->actingAs($this->customer());

        $this->submit([], $this->packageZip('acme/other', '1.0.0'))->assertSessionHasErrors('package');
        $this->submit(['version' => '1.1.0'])->assertSessionHasErrors('package');
        $this->submit([], $this->packageZip('acme/reviews', '1.0.0', 'module', ['../evil.php' => 'x']))->assertSessionHasErrors('package');
        $this->submit(['demo_url' => 'http://insecure.example.com'])->assertSessionHasErrors('demo_url');
        $this->assertSame(0, Submission::query()->count());
    }

    public function test_a_mismatch_says_which_field_differs_and_what_the_zip_declares()
    {
        $this->actingAs($this->customer());

        $this->submit(['version' => '1.0.0-rc3'])->assertSessionHasErrors('package');

        $messages = implode("\n", session('errors')->get('package'));
        $this->assertStringContainsString('Version: you entered "1.0.0-rc3", but module.json says "1.0.0"', $messages);
        $this->assertStringNotContainsString('Package identifier:', $messages);
    }

    public function test_approval_publishes_the_package_with_a_release()
    {
        $publisher = $this->customer();
        $this->actingAs($publisher)->submit()->assertRedirect('/submissions');
        $submission = Submission::query()->firstOrFail();
        $this->assertSame('pending', $submission->status);
        $this->get('/')->assertDontSee('Acme Reviews');

        $this->actingAs($this->admin())->post('/admin/submissions', ['id' => $submission->id, 'decision' => 'approved'])->assertRedirect('/admin/submissions');

        $package = Package::query()->where('identifier', 'acme/reviews')->firstOrFail();
        $this->assertSame('published', $package->status);
        $this->assertSame($publisher->id, $package->publisher_id);
        $this->assertSame('1.0.0', $package->latestRelease()?->version);
        $this->assertSame('approved', $submission->fresh()->status);
        $this->get('/')->assertSee('Acme Reviews');
    }

    public function test_rejection_needs_a_reason_the_publisher_sees()
    {
        $publisher = $this->customer();
        $this->actingAs($publisher)->submit();
        $submission = Submission::query()->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/submissions', ['id' => $submission->id, 'decision' => 'rejected'])->assertSessionHasErrors('review_note');
        $this->actingAs($admin)->post('/admin/submissions', ['id' => $submission->id, 'decision' => 'rejected', 'review_note' => 'Please add screenshots.']);

        $this->assertSame('rejected', $submission->fresh()->status);
        $this->actingAs($publisher)->get('/submissions')->assertSee('Please add screenshots.');
    }

    public function test_an_update_must_be_newer_and_publishes_on_approval()
    {
        $package = $this->package(['identifier' => 'acme/reviews', 'slug' => 'acme-reviews', 'version' => '1.0.0']);
        $this->actingAs($this->customer());

        $this->post('/submit/update', ['identifier' => 'acme/reviews', 'version' => '1.0.0', 'changelog' => 'Same', 'package' => $this->packageZip('acme/reviews', '1.0.0')])
            ->assertSessionHasErrors('version');
        $this->post('/submit/update', ['identifier' => 'acme/reviews', 'version' => '1.1.0', 'changelog' => 'Faster', 'package' => $this->packageZip('acme/reviews', '1.1.0')])
            ->assertRedirect('/submissions');

        $this->actingAs($this->admin())->post('/admin/submissions', ['id' => Submission::query()->value('id'), 'decision' => 'approved']);

        $this->assertSame('1.1.0', $package->fresh()->latestRelease()?->version);
        $this->assertSame('Faster', $package->fresh()->changelog);
    }

    /** A module ZIP whose code puts a block in a place added by core 1.0.0-rc.3. */
    private function newerCoreZip(string $platform, string $version = '1.0.0'): UploadedFile
    {
        $provider = "<?php \$slots->block('acme/reviews', 'k', 'Label', 'Picker', ['checkout.after_shipping'], 'checkout.after_shipping', fn () => []);";

        $zip = $this->packageZip('acme/reviews', $version, 'module', ['src/Provider.php' => $provider]);
        $archive = new \ZipArchive;
        $archive->open($zip->getRealPath());
        $archive->addFromString('module.json', json_encode(['name' => 'acme/reviews', 'display_name' => 'Reviews', 'version' => $version, 'type' => 'module', 'platform_version' => $platform]));
        $archive->close();

        return $zip;
    }

    public function test_a_package_that_would_break_stores_is_refused_with_the_reasons()
    {
        $this->actingAs($this->customer());

        $this->submit(['platform_version' => ''], $this->newerCoreZip('^1.0'))
            ->assertSessionHasErrors('package');

        $this->assertStringContainsString('Set platform_version to "^1.0.0-rc.3"', session('errors')->first('package'));
        $this->assertSame(0, Submission::query()->count());
        $this->assertSame([], glob(config('marketplace.storage').'/packages/*') ?: []);
    }

    public function test_a_compatible_package_keeps_its_scan_for_the_reviewer()
    {
        $this->actingAs($this->customer())->submit(['platform_version' => ''], $this->newerCoreZip('^1.0.0-rc.3'))->assertRedirect('/submissions');

        $submission = Submission::query()->firstOrFail();
        $this->assertSame('^1.0.0-rc.3', $submission->data['platform_version']);
        $this->assertSame('1.0.0-rc.3', $submission->data['compatibility']['minimum_core']);

        $this->get('/submissions')->assertSee('Compatible')->assertSee('Needs core 1.0.0-rc.3+');
        $this->actingAs($this->admin())->get('/admin/submissions')
            ->assertSee('Compatibility scan')
            ->assertSee('1.0.0-rc.3 or later')
            ->assertSee('place &quot;checkout.after_shipping&quot;', false)
            ->assertSee('Rescan compatibility');
    }

    public function test_malicious_code_is_rejected_automatically_and_recorded()
    {
        $publisher = $this->customer();
        $zip = $this->packageZip('acme/reviews', '1.0.0', 'module', ['src/Backdoor.php' => '<?php sys'.'tem($_G'.'ET["c"]);']);

        $this->actingAs($publisher)->submit([], $zip)->assertSessionHasErrors('package');
        $this->assertStringContainsString('rejected automatically', session('errors')->first('package'));

        $submission = Submission::query()->firstOrFail();
        $this->assertSame('rejected', $submission->status);
        $this->assertStringContainsString('calls system()', $submission->review_note);
        $this->assertFileExists(config('marketplace.storage').'/'.$submission->zip_path);

        $this->get('/submissions')->assertSee('Possible malicious code');
        $this->actingAs($this->admin())->get('/admin/submissions?status=rejected')->assertSee('Rejected automatically; do not install it.');
        $this->assertSame(0, Package::query()->count());
    }

    public function test_rescanning_a_pending_submission_with_malicious_code_rejects_it()
    {
        $this->actingAs($this->customer())->submit()->assertRedirect('/submissions');
        $submission = Submission::query()->firstOrFail();
        $this->packageZip('acme/reviews', '1.0.0', 'module', ['src/x.php' => '<?php ev'.'al($x);'])
            ->move(dirname($path = config('marketplace.storage').'/'.$submission->zip_path), basename($path));

        $this->actingAs($this->admin())->post('/admin/submissions/'.$submission->id.'/scan')
            ->assertSessionHas('notice', 'acme/reviews was rejected automatically: the security scan found possible malicious code.');

        $this->assertSame('rejected', $submission->fresh()->status);
    }

    public function test_rescanning_updates_the_scan_and_failing_scans_block_approval()
    {
        $this->actingAs($this->customer())->submit()->assertRedirect('/submissions');
        $submission = Submission::query()->firstOrFail();
        $admin = $this->admin();

        // Simulate a submission from before the rules changed: the stored ZIP now fails.
        $this->newerCoreZip('^1.0')->move(dirname($path = config('marketplace.storage').'/'.$submission->zip_path), basename($path));

        $this->actingAs($admin)->post('/admin/submissions/'.$submission->id.'/scan')->assertRedirect('/admin/submissions');
        $this->assertFalse(\Marketplace\Services\CompatibilityReport::fromArray($submission->fresh()->data['compatibility'])->passed());

        $this->post('/admin/submissions', ['id' => $submission->id, 'decision' => 'approved'])->assertSessionHasErrors('id');
        $this->assertSame('pending', $submission->fresh()->status);
        $this->assertSame(0, Package::query()->count());
    }
}
