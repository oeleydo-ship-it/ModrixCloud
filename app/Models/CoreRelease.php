<?php

namespace Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/** A version of the core store platform, uploaded as a draft, then published or withdrawn. */
class CoreRelease extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const WITHDRAWN = 'withdrawn';

    protected $fillable = ['version', 'notes', 'min_php', 'path', 'sha256', 'size', 'status', 'signed_metadata', 'signature', 'published_at'];

    protected function casts(): array
    {
        return ['signed_metadata' => 'array', 'published_at' => 'datetime', 'size' => 'integer'];
    }
}
