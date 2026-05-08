<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('roles.view');
    }

    public function view(User $actor, Role $role): bool
    {
        if (! $actor->can('roles.view')) {
            return false;
        }

        return $this->roleBelongsToActorScope($actor, $role);
    }

    public function create(User $actor): bool
    {
        return $actor->can('roles.manage');
    }

    public function update(User $actor, Role $role): bool
    {
        if ($this->isTemplate($role)) {
            return false;
        }

        if (! $actor->can('roles.manage')) {
            return false;
        }

        return $this->roleBelongsToActorScope($actor, $role);
    }

    public function delete(User $actor, Role $role): bool
    {
        return $this->update($actor, $role);
    }

    protected function isTemplate(Role $role): bool
    {
        return $role->team_id === null;
    }

    protected function roleBelongsToActorScope(User $actor, Role $role): bool
    {
        if ($this->isTemplate($role)) {
            return true;
        }

        return $role->team_id === $actor->organisation_id;
    }
}
