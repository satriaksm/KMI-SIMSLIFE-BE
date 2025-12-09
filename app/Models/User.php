<?php

namespace App\Models;

use App\Models\Merchant;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'profile_picture_path',
        'nik',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles->contains(fn($r) => strcasecmp($r->name, $role) === 0);
    }

    public function hasAnyRole(array $roles): bool
    {
        $roles = array_map('strtolower', $roles);
        return $this->roles->contains(fn($r) => in_array(strtolower($r->name), $roles, true));
    }

    public function getProfilePictureAttribute()
    {
        if (empty($this->profile_picture_path)) {
            return url('storage/profilepics/profilepicdefault.png');
        }
        return url('storage/' . ltrim($this->profile_picture_path, '/'));
    }

    public function createdEvents()
    {
        return $this->hasMany(Event::class, 'created_by');
    }

    public function voucherUsages()
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function reviewedMerchants()
    {
        return $this->hasMany(Merchant::class, 'reviewed_by');
    }
}
