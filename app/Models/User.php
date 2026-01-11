<?php

namespace App\Models;

use App\Models\Merchant;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'profile_picture_path',
        'nik',
        'status',
        'email_verified_at',
        'password',
    ];

    protected $guarded = [
        'id',
        'remember_token',
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

    /**
     * Activity metric (1-to-1)
     */
    public function activityMetric()
    {
        return $this->hasOne(UserActivityMetric::class);
    }

    /**
     * Activity snapshots (1-to-many)
     */
    public function activitySnapshots()
    {
        return $this->hasMany(UserActivitySnapshot::class);
    }

    /**
     * Alerts related to this user
     */
    public function alerts()
    {
        return $this->morphMany(Alert::class, 'alertable');
    }

    /**
     * Admin actions targeting this user
     */
    public function adminActions()
    {
        return $this->morphMany(AdminAction::class, 'target');
    }

    /**
     * Community posts
     */
    public function communityPosts()
    {
        return $this->hasMany(CommunityPost::class);
    }

    /**
     * Post comments
     */
    public function postComments()
    {
        return $this->hasMany(PostComment::class);
    }

    /**
     * Orders (if exists)
     * NOTE: Current schema doesn't have user_id in orders table
     * This is a placeholder for future implementation
     */
    public function orders()
    {
        if (DB::getSchemaBuilder()->hasColumn('orders', 'user_id')) {
            return $this->hasMany(Order::class);
        }

        return $this->hasMany(Order::class)->whereRaw('1 = 0'); // Always empty
    }

    public function canLogin(): bool
    {
        return in_array($this->status, ['active', 'declining', 'watchlist']);
    }

    public function isFullyActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBlocked(): bool
    {
        return in_array($this->status, ['suspended', 'inactive']);
    }

    public function needsAttention(): bool
    {
        return in_array($this->status, ['declining', 'watchlist', 'suspended']);
    }

    public function scopeCanLogin($query)
    {
        return $query->whereIn('status', ['active', 'declining', 'watchlist']);
    }

    public function scopeBlocked($query)
    {
        return $query->whereIn('status', ['suspended', 'inactive']);
    }
}
