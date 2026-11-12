<?php

namespace Marketplace\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A customer or publisher; `is_admin` users manage the marketplace.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $banned_at
 * @property string|null $ban_reason
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_admin' => 'boolean', 'banned_at' => 'datetime', 'email_verified_at' => 'datetime'];
    }

    /** Stored lowercase so sign-in and uniqueness ignore case on every database. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function connections(): HasMany
    {
        return $this->hasMany(StoreConnection::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
