<?php

namespace Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A one-time pairing code, valid for five minutes, exchanged by the store
 * together with its PKCE verifier for a connection token.
 *
 * @property int $id
 * @property int $user_id
 * @property string $instance_id
 * @property string $store_name
 * @property string $domain
 * @property string $code_hash
 * @property string $code_challenge
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class ConnectionCode extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
