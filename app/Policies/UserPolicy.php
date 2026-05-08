<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->organisation_id === $target->organisation_id
            && $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    public function update(User $actor, User $target): bool
    {
        if ($target->isSuperAdmin()) {
            return false;
        }

        return $actor->organisation_id === $target->organisation_id
            && $actor->can('users.update');
    }

    public function delete(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        if ($target->isSuperAdmin()) {
            return false;
        }

        return $actor->organisation_id === $target->organisation_id
            && $actor->can('users.delete');
    }
}
