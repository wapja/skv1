<?php

arch('tenant-owned models implement the TenantOwned marker interface')
    ->expect('App\Models')
    ->classes()
    ->toImplement('App\Contracts\TenantOwned')
    ->ignoring(['App\Models\Organisation', 'App\Models\Invitation']);

arch('services do not depend on http controllers')
    ->expect('App\Services')
    ->not->toUse('App\Http\Controllers');

arch('models do not call DB facade directly')
    ->expect('App\Models')
    ->not->toUse('Illuminate\Support\Facades\DB');
