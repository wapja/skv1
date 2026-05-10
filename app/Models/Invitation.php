<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'accepted', 'expired', 'cancelled'];

    protected $fillable = [
        'user_id',
        'invited_by',
        'token',
        'expires_at',
        'reminder_sent_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->accepted_at !== null) {
                return 'accepted';
            }
            if ($this->user?->trashed()) {
                return 'cancelled';
            }
            if ($this->expires_at?->isPast()) {
                return 'expired';
            }

            return 'pending';
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereHas('user');
    }

    public function scopeWhereStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'pending' => $query->active()->where('expires_at', '>=', now()),
            'expired' => $query->active()->where('expires_at', '<', now()),
            'accepted' => $query->whereNotNull('accepted_at'),
            'cancelled' => $query->whereHas('user', fn ($q) => $q->onlyTrashed()),
            default => $query,
        };
    }

    public function scopeOrderByStatus(Builder $query, string $direction): Builder
    {
        return $query->orderByRaw(
            "CASE
                WHEN invitations.accepted_at IS NOT NULL THEN 1
                WHEN (SELECT deleted_at FROM users WHERE users.id = invitations.user_id) IS NOT NULL THEN 4
                WHEN invitations.expires_at < ? THEN 3
                ELSE 2
              END {$direction}",
            [now()]
        );
    }
}
