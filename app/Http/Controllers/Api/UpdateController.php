<?php

namespace Marketplace\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marketplace\Models\License;
use Marketplace\Services\CoreReleases;
use Marketplace\Services\Releases;

/**
 * POST updates — the newest signed release this store may install. When a
 * newer version exists but needs a newer core than the store runs, the reply
 * says so in core_update (required constraint, first and latest core).
 */
class UpdateController
{
    public function __invoke(Request $request, Releases $releases, CoreReleases $cores): JsonResponse
    {
        $data = ApiError::validate($request, [
            'key' => ['required', 'string', 'max:64'],
            'instance_id' => ['required', 'string', 'max:128'],
            'installed_version' => ['nullable', 'string', 'max:32'],
            'platform_version' => ['nullable', 'string', 'max:32'],
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $license = License::query()->with('package')->where('key', $data['key'])->first();

        if (! $license instanceof License) {
            return ApiError::response(404, 'invalid_key', 'This license key is not valid.');
        }

        if ($license->status !== 'active') {
            return response()->json(['available' => false, 'reason' => 'This license is no longer active.', 'release' => null]);
        }

        if (! $license->activations()->where('instance_id', $data['instance_id'])->where('active', true)->exists()) {
            return response()->json(['available' => false, 'reason' => 'Activate this license on the store first.', 'release' => null]);
        }

        $platform = $data['platform_version'] ?? null;
        $release = $releases->newestFor($license->package, $data['installed_version'] ?? null, $platform);

        if ($release !== null) {
            return response()->json(['available' => true, 'release' => $releases->payload($release)]);
        }

        $blocked = $platform === null || $platform === '' ? null : $releases->newestNeedingCore($license->package, $data['installed_version'] ?? null, $platform);

        if ($blocked === null) {
            return response()->json(['available' => false, 'reason' => 'You have the latest version for this platform.', 'release' => null]);
        }

        $core = $cores->requirement($blocked->platform_version, $platform);
        $target = $core['minimum_core'] !== null
            ? "Update the core to {$core['minimum_core']} or later first."
            : 'A core release that supports it is not available yet.';

        return response()->json([
            'available' => false,
            'reason' => "Version {$blocked->version} needs Modrix Commerce core {$blocked->platform_version}, but this store runs {$platform}. {$target}",
            'release' => null,
            'core_update' => ['version' => $blocked->version, ...$core],
        ]);
    }
}
