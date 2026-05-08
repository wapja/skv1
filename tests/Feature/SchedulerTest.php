<?php

use Illuminate\Console\Scheduling\Schedule;

it('registers the backup, cleanup and invitation purge schedule entries', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $events = $schedule->events();
    $descriptions = collect($events)->map(fn ($event) => $event->description ?? $event->command ?? '')->all();
    $rawCommands = collect($events)->map(fn ($event) => $event->command ?? '')->all();

    expect(collect($rawCommands)->contains(fn ($c) => str_contains((string) $c, 'backup:clean')))->toBeTrue('backup:clean missing')
        ->and(collect($rawCommands)->contains(fn ($c) => str_contains((string) $c, 'backup:run --only-db')))->toBeTrue('backup:run missing')
        ->and(collect($descriptions)->contains(fn ($d) => str_contains((string) $d, 'invitations:purge-expired')))->toBeTrue('purge schedule missing');
});
