<?php

namespace App\Models;

use App\Contracts\TenantOwned;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole implements TenantOwned
{
    use SoftDeletes;
}
