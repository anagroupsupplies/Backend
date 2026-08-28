<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['firebase_uid', 'name', 'email', 'password', 'photo_url', 'phone', 'address', 'role', 'is_active', 'preferences', 'last_login_at', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'master'], true);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->isAdmin();
    }

    public function isMaster(): bool
    {
        return $this->role === 'master';
    }

    public function isMainAdmin(): bool
    {
        return $this->isMaster();
    }

    public function isSeller(): bool
    {
        return $this->role === 'seller';
    }

    public function isBuyer(): bool
    {
        return $this->role === 'user';
    }

    public function canSell(): bool
    {
        return $this->isSeller() || $this->isAdmin();
    }

    public function ownsProduct(Product $product): bool
    {
        return $this->isSeller() && $product->seller_id === $this->id;
    }

    public function apiTokens()
    {
        return $this->hasMany(ApiToken::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    /** Escrowed money belonging to this seller. */
    public function escrowHoldings()
    {
        return $this->hasMany(EscrowHolding::class, 'seller_id');
    }

    public function shop()
    {
        return $this->hasOne(Shop::class, 'seller_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public const ROLE_BUYER = 'user';

    public const ROLE_SELLER = 'seller';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MASTER = 'master';
}
