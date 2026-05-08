<?php

use App\Models\Organisation;

it('resolves tenant from subdomain', function () {
    config(['app.apex_domain' => 'skv1.test']);
    $org = Organisation::factory()->create(['slug' => 'school1']);

    $this->get('https://school1.skv1.test/')->assertOk();

    expect(app('currentOrganisation')->id)->toBe($org->id);
});

it('aborts 404 on unknown subdomain', function () {
    config(['app.apex_domain' => 'skv1.test']);

    $this->get('https://nonexistent.skv1.test/')->assertNotFound();
});

it('skips tenant resolution on apex host', function () {
    config(['app.apex_domain' => 'skv1.test']);

    $this->get('https://skv1.test/')->assertOk();

    expect(app()->bound('currentOrganisation'))->toBeFalse();
});

it('skips tenant resolution on admin host', function () {
    config([
        'app.apex_domain' => 'skv1.test',
        'app.admin_host' => 'admin.skv1.test',
    ]);

    $this->get('https://admin.skv1.test/')->assertOk();

    expect(app()->bound('currentOrganisation'))->toBeFalse();
});

it('exposes the tenant() helper', function () {
    config(['app.apex_domain' => 'skv1.test']);
    $org = Organisation::factory()->create(['slug' => 'school2']);

    $this->get('https://school2.skv1.test/');

    expect(tenant())->not->toBeNull()
        ->and(tenant()->id)->toBe($org->id);
});

it('returns null from tenant() when no tenant is bound', function () {
    expect(tenant())->toBeNull();
});
