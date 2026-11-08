<?php

namespace Marketplace\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marketplace\Models\CoreDownloadToken;
use Marketplace\Models\CoreRelease;
use Marketplace\Services\Connections;
use Marketplace\Services\CoreReleases;
use Symfony\Component\HttpFoundation\Response;

/**
 * Core platform updates: GET core/releases lists signed releases newer than
 * the store's version; POST core/downloads (connected stores only) issues a
 * one-time link (10 minutes); GET core/download/{token} streams the ZIP once.
 */
class CoreReleaseController
{
    public function __construct(private readonly CoreReleases $releases, private readonly Connections $connections) {}

    public function index(Request $request): JsonResponse
    {
        $current = $request->query('current');
        $php = $request->query('php');

        $releases = $this->releases->newerThan(
            is_string($current) ? $current : null,
            is_string($php) ? $php : null,
        )->map(fn (CoreRelease $r) => $this->releases->payload($r))->values();

        return response()->json(['releases' => $releases]);
    }

    public function request(Request $request): JsonResponse
    {
        $connection = $this->connections->authenticate($request->bearerToken());

        if ($connection === null) {
            return ApiError::response(401, 'not_connected', 'This store is no longer connected to a Modrix Cloud account. Connect it again.');
        }

        $data = ApiError::validate($request, [
            'release_id' => ['required', 'integer'],
            'instance_id' => ['required', 'string', 'max:128'],
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $release = CoreRelease::query()->whereKey((int) $data['release_id'])->where('status', CoreRelease::PUBLISHED)->first();

        if (! $release instanceof CoreRelease) {
            return ApiError::response(404, 'release_not_found', 'This release does not exist.');
        }

        $token = Str::random(64);
        $expires = now()->addMinutes((int) config('marketplace.download_minutes', 10));
        CoreDownloadToken::query()->create([
            'token_hash' => hash('sha256', $token),
            'core_release_id' => $release->id,
            'store_connection_id' => $connection->id,
            'instance_id' => $data['instance_id'],
            'expires_at' => $expires,
        ]);

        return response()->json([
            'url' => url('/api/marketplace/v1/core/download/'.$token),
            'expires_at' => $expires->toIso8601String(),
            'release' => $this->releases->payload($release),
        ]);
    }

    public function download(string $token): Response
    {
        // Claim the token atomically so it can only be used once. A missing
        // file does not use it up.
        $result = DB::transaction(function () use ($token) {
            $row = CoreDownloadToken::query()->with('release')->whereKey(hash('sha256', $token))->lockForUpdate()->first();

            if (! $row instanceof CoreDownloadToken || $row->used_at !== null || $row->expires_at->isPast() || $row->release?->status !== CoreRelease::PUBLISHED) {
                return ApiError::response(410, 'link_expired', 'This download link has expired or was already used.');
            }

            if (! is_file($this->releases->path($row->release))) {
                return ApiError::response(404, 'release_not_found', 'The release file is not available right now. Try again later.');
            }

            $row->forceFill(['used_at' => now()])->save();

            return $row;
        });

        if (! $result instanceof CoreDownloadToken) {
            return $result;
        }

        return response()->download($this->releases->path($result->release), 'modrix-commerce-'.$result->release->version.'.zip', ['Content-Type' => 'application/zip']);
    }
}
