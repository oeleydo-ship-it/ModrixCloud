<?php

namespace Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A single-use core release download link; only the token's SHA-256 is stored. */
class CoreDownloadToken extends Model
{
    protected $primaryKey = 'token_hash';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['token_hash', 'core_release_id', 'store_connection_id', 'instance_id', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(CoreRelease::class, 'core_release_id');
    }
}
