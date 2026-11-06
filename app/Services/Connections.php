<?php

namespace Marketplace\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marketplace\Models\BannedStore;
use Marketplace\Models\ConnectionCode;
use Marketplace\Models\StoreConnection;
use Marketplace\Models\User;

/**
 * Pairing stores with customer accounts (Modrix Cloud Connector).
 *
 * OAuth-style authorization code with PKCE (S256): the customer approves a
 * store in the browser, the store exchanges the one-time code with its
 * secret verifier for a bearer token. Only SHA-256 hashes of codes and
 * tokens are stored.
 */
final class Connections
{
    public const CODE_MINUTES = 5;

    /** The store may only be sent back to its own domain (https, or http for local development). */
    public static function returnUrlMatches(string $returnUrl, string $domain): bool
    {
        $parts = parse_url($returnUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $expected = strtolower(explode(':', $domain)[0]);

        return in_array($scheme, ['https', 'http'], true)
            && $host !== ''
            && $host === $expected
            // Plain http only for local development stores.
            && ($scheme === 'https' || in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.test'));
    }

    /**
     * Why this account or store may not connect, or null when it may.
     */
    public function refusal(?User $user, ?string $instanceId, ?string $domain): ?string
    {
        if ($user?->banned_at !== null) {
            return 'This account is suspended and cannot connect stores.';
        }

        return BannedStore::blocks($instanceId, $domain)
            ? 'This store is not allowed to connect to this marketplace.'
            : null;
    }

    /**
     * @return string the one-time code to hand back to the store
     */
    public function issueCode(User $user, string $instanceId, string $storeName, string $domain, string $challenge): string
    {
        $code = Str::random(48);

        ConnectionCode::query()->forceCreate([
            'user_id' => $user->id,
            'instance_id' => $instanceId,
            'store_name' => $storeName,
            'domain' => $domain,
            'code_hash' => hash('sha256', $code),
            'code_challenge' => $challenge,
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ]);

        return $code;
    }

    /**
     * Exchanges a code for a token; null when the code is unknown, used,
     * expired, for another installation, or the verifier does not match.
     *
     * @return array{connection: StoreConnection, token: string}|null
     */
    public function exchange(string $code, string $verifier, string $instanceId): ?array
    {
        return DB::transaction(function () use ($code, $verifier, $instanceId) {
            /** @var ConnectionCode|null $pending */
            $pending = ConnectionCode::query()->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();

            if ($pending === null || $pending->used_at !== null || $pending->expires_at->isPast()) {
                return null;
            }

            // Used either way: a wrong verifier burns the code.
            $pending->forceFill(['used_at' => now()])->save();

            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            if (! hash_equals($pending->code_challenge, $challenge) || ! hash_equals($pending->instance_id, $instanceId)) {
                return null;
            }

            // Banned after approving, before the exchange.
            if ($this->refusal(User::query()->find($pending->user_id), $pending->instance_id, $pending->domain) !== null) {
                return null;
            }

            // One live connection per installation: reconnecting replaces it.
            StoreConnection::query()->where('instance_id', $instanceId)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $token = Str::random(64);
            $connection = StoreConnection::query()->forceCreate([
                'user_id' => $pending->user_id,
                'instance_id' => $pending->instance_id,
                'store_name' => $pending->store_name,
                'domain' => $pending->domain,
                'token_hash' => hash('sha256', $token),
            ]);

            return ['connection' => $connection, 'token' => $token];
        });
    }

    public function authenticate(?string $token): ?StoreConnection
    {
        if ($token === null || strlen($token) !== 64) {
            return null;
        }

        $connection = StoreConnection::query()->with('user')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();

        // Banning revokes connections; this also covers a ban added in the meantime.
        if ($connection !== null && $this->refusal($connection->user, $connection->instance_id, $connection->domain) !== null) {
            return null;
        }

        if ($connection !== null && ($connection->last_used_at === null || $connection->last_used_at->lt(now()->subMinute()))) {
            $connection->forceFill(['last_used_at' => now()])->save();
        }

        return $connection;
    }

    public function revoke(StoreConnection $connection): void
    {
        $connection->forceFill(['revoked_at' => now()])->save();
    }
}
