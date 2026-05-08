<?php

use App\Models\Organisation;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('cascades soft-delete from organisation to its users', function () {
    $org = Organisation::factory()->create();
    User::factory()->for($org)->count(3)->create();

    $org->delete();

    expect(User::withoutTenantScope()->where('organisation_id', $org->id)->count())->toBe(0)
        ->and(User::withoutTenantScope()->withTrashed()->where('organisation_id', $org->id)->count())->toBe(3);
});

it('writes the same deleted_at timestamp on the organisation and its cascade users', function () {
    Carbon::setTestNow('2026-04-01 09:30:00');

    $org = Organisation::factory()->create();
    User::factory()->for($org)->count(2)->create();

    $org->delete();
    $orgTimestamp = $org->fresh()->deleted_at;

    $userTimestamps = User::withoutTenantScope()
        ->withTrashed()
        ->where('organisation_id', $org->id)
        ->pluck('deleted_at');

    expect($userTimestamps->every(fn ($ts) => $ts->equalTo($orgTimestamp)))->toBeTrue();

    Carbon::setTestNow();
});

it('restore cascade only un-trashes rows that match the org deleted_at exactly', function () {
    Carbon::setTestNow('2026-04-01 09:00:00');

    $org = Organisation::factory()->create();
    [$preTrashed, $cascadeA, $cascadeB] = User::factory()->for($org)->count(3)->create()->all();

    // Pre-trash one user at a different timestamp
    $preTrashed->delete();
    DB::table('users')
        ->where('id', $preTrashed->id)
        ->update(['deleted_at' => '2026-04-01 08:00:00']);

    Carbon::setTestNow('2026-04-01 09:30:00');
    $org->delete();

    // Now restore
    $org->restore();

    expect(User::withoutTenantScope()->find($cascadeA->id))->not->toBeNull()
        ->and(User::withoutTenantScope()->find($cascadeB->id))->not->toBeNull()
        ->and(User::withoutTenantScope()->find($preTrashed->id))->toBeNull();

    Carbon::setTestNow();
});

it('does not cascade when force-deleting the organisation', function () {
    $org = Organisation::factory()->create();
    User::factory()->for($org)->count(2)->create();

    DB::table('users')->where('organisation_id', $org->id)->delete(); // remove FK refs first
    $org->forceDelete();

    expect(Organisation::withTrashed()->find($org->id))->toBeNull();
});
