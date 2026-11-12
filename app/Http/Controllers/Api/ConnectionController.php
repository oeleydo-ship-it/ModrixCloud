<?php

namespace Marketplace\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marketplace\Models\License;
use Marketplace\Models\StoreConnection;
use Marketplace\Services\Catalog;
use Marketplace\Services\Connections;
use Marketplace\Services\Licensing;
use Marketplace\Services\Subscriptions;

/**
 * Connected-store API (Modrix Cloud Connector), under
 * /api/marketplace/v1/connections. Every call except `token` needs the
 * store's bearer token; the account behind it is never taken from input.
 */
class ConnectionController
{
    public function __construct(private readonly Connections $connections) {}

    /** Exchanges a one-time pairing code and PKCE verifier for a connection token. */
    public function token(Request $request): JsonResponse
    {
        $data = ApiError::validate($request, [
            'code' => ['required', 'string', 'max:128'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'instance_id' => ['required', 'string', 'max:128'],
        ]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $result = $this->connections->exchange($data['code'], $data['code_verifier'], $data['instance_id']);

        if ($result === null) {
            return ApiError::response(400, 'invalid_grant', 'The connection request expired or was already used. Start connecting again from the store.');
        }

        return response()->json([
            'token' => $result['token'],
            'account' => $this->account($result['connection']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $connection = $this->connection($request);

        return $connection === null ? $this->unauthorized() : response()->json(['account' => $this->account($connection)]);
    }

    /** The account's licenses, with keys, so the store can activate them without copy-pasting. */
    public function licenses(Request $request): JsonResponse
    {
        $connection = $this->connection($request);

        if ($connection === null) {
            return $this->unauthorized();
        }

        $licenses = License::query()->where('user_id', $connection->user_id)
            ->with(['package', 'activations' => fn ($q) => $q->where('active', true)])
            ->get()
            ->map(fn (License $license) => $this->licenseRow($license, $connection))
            ->values();

        return response()->json(['licenses' => $licenses]);
    }

    /**
     * The account's license for one package: an existing one, or a new one
     * for a free package. Paid packages that were not bought are refused.
     */
    public function claim(Request $request, Catalog $catalog, Licensing $licensing): JsonResponse
    {
        $connection = $this->connection($request);

        if ($connection === null) {
            return $this->unauthorized();
        }

        $data = ApiError::validate($request, ['identifier' => ['required', 'string', 'max:128']]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $product = $catalog->published()->firstWhere('model.identifier', $data['identifier']);

        if ($product === null) {
            return ApiError::response(404, 'not_found', 'This package is not available on Modrix Cloud.');
        }

        $user = $connection->user;

        // Paid packages: bought, or included in the customer's subscription.
        $license = $product['premium'] ? app(Subscriptions::class)->claim($user, $product['model']) : $licensing->issue($user, $product['model'], 'free');

        if ($license === null) {
            return ApiError::response(402, 'not_owned', 'Buy this package on Modrix Cloud first, or subscribe if it is included in the subscription; it is then ready to install here.');
        }

        if ($license->status !== 'active') {
            return ApiError::response(403, 'license_inactive', 'Your license for this package was '.$license->status.'.');
        }

        $license->load(['package', 'activations' => fn ($q) => $q->where('active', true)]);

        return response()->json(['license' => $this->licenseRow($license, $connection)]);
    }

    /**
     * "Move here": frees the account's own license from the store it is
     * active on, so this store can activate it (same as Account → Disconnect store).
     */
    public function release(Request $request): JsonResponse
    {
        $connection = $this->connection($request);

        if ($connection === null) {
            return $this->unauthorized();
        }

        $data = ApiError::validate($request, ['identifier' => ['required', 'string', 'max:128']]);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $license = License::query()->where('user_id', $connection->user_id)
            ->whereHas('package', fn ($q) => $q->where('identifier', $data['identifier']))
            ->first();

        if ($license === null) {
            return ApiError::response(404, 'not_found', 'Your account has no license for this package.');
        }

        $license->activations()->where('active', true)->where('instance_id', '!=', $connection->instance_id)
            ->update(['active' => false, 'deactivated_at' => now()]);

        return response()->json(['released' => true]);
    }

    /** Disconnect, from the store. */
    public function revoke(Request $request): JsonResponse
    {
        $connection = $this->connection($request);

        if ($connection === null) {
            return $this->unauthorized();
        }

        $this->connections->revoke($connection);

        return response()->json(['revoked' => true]);
    }

    private function connection(Request $request): ?StoreConnection
    {
        return $this->connections->authenticate($request->bearerToken());
    }

    private function unauthorized(): JsonResponse
    {
        return ApiError::response(401, 'not_connected', 'This store is no longer connected to a Modrix Cloud account. Connect it again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function account(StoreConnection $connection): array
    {
        $connection->loadMissing('user');

        return [
            'name' => $connection->user->name,
            'email' => $connection->user->email,
            'store_name' => $connection->store_name,
            'connected_at' => $connection->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function licenseRow(License $license, StoreConnection $connection): array
    {
        $active = $license->activations->first();

        return [
            'identifier' => $license->package->identifier,
            'type' => $license->package->type,
            'name' => $license->package->name,
            'key' => $license->key,
            'status' => $license->status,
            'kind' => $license->type,
            // Active on a different installation: the store offers "Move here".
            'in_use_elsewhere' => $active !== null && $active->instance_id !== $connection->instance_id,
            'active_domain' => $active?->domain,
        ];
    }
}
