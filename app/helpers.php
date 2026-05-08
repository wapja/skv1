<?php

use App\Models\Organisation;

if (! function_exists('tenant')) {
    function tenant(): ?Organisation
    {
        return app()->bound('currentOrganisation') ? app('currentOrganisation') : null;
    }
}
