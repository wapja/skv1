<?php

it('TelescopeServiceProvider register() guards against non-local environments', function () {
    $source = file_get_contents(base_path('app/Providers/TelescopeServiceProvider.php'));
    expect($source)->toContain("environment('local')");
});

it('routes/console.php schedules backup:clean and backup:run --only-db', function () {
    $source = file_get_contents(base_path('routes/console.php'));
    expect($source)->toContain('backup:clean')
        ->and($source)->toContain('backup:run --only-db')
        ->and($source)->toContain('invitations:purge-expired');
});
