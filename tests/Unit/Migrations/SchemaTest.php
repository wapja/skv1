<?php

use Illuminate\Support\Facades\Schema;

it('has organisations table with expected columns', function () {
    expect(Schema::hasTable('organisations'))->toBeTrue();
    expect(Schema::hasColumns('organisations', [
        'id', 'name', 'slug', 'description',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('has users table extended with tenant + activation + 2fa columns', function () {
    expect(Schema::hasColumns('users', [
        'organisation_id', 'status',
        'activation_token', 'activation_expires_at', 'activated_at',
        'two_factor_secret', 'two_factor_enabled_at',
        'locale', 'deleted_at',
    ]))->toBeTrue();
});

it('has users table with profile fields and no legacy name column', function () {
    expect(Schema::hasColumns('users', [
        'first_name', 'middle_name', 'last_name',
        'internal_id', 'phone', 'address',
        'start_date', 'end_date',
    ]))->toBeTrue();
    expect(Schema::hasColumn('users', 'name'))->toBeFalse();
});

it('has invitations table with expected columns', function () {
    expect(Schema::hasTable('invitations'))->toBeTrue();
    expect(Schema::hasColumns('invitations', [
        'id', 'user_id', 'invited_by', 'token', 'expires_at',
        'reminder_sent_at', 'accepted_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});
