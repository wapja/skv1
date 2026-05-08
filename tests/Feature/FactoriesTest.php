<?php

use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\User;

it('creates an organisation via factory', function () {
    $org = Organisation::factory()->create();

    expect($org->id)->toBeGreaterThan(0)
        ->and($org->slug)->not->toBeEmpty();
});

it('creates an active user via factory', function () {
    $org = Organisation::factory()->create();
    $user = User::factory()->for($org)->create();

    expect($user->status)->toBe('active')
        ->and($user->locale)->toBe('nl')
        ->and($user->organisation_id)->toBe($org->id);
});

it('creates a pending-activation user via state', function () {
    $org = Organisation::factory()->create();
    $user = User::factory()->for($org)->pendingActivation()->create();

    expect($user->status)->toBe('pending_activation')
        ->and($user->activation_token)->toHaveLength(64)
        ->and($user->activation_expires_at)->not->toBeNull();
});

it('creates an invitation via factory', function () {
    $invitation = Invitation::factory()->create();

    expect($invitation->token)->toHaveLength(64)
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and($invitation->user_id)->toBeGreaterThan(0)
        ->and($invitation->invited_by)->toBeGreaterThan(0);
});
