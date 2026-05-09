<?php

namespace App\Policies;

use App\Models\Organisation;
use App\Models\User;

class OrganisationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('organisations.manage');
    }

    public function view(User $actor, Organisation $organisation): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $actor->organisation_id === $organisation->id
            && $actor->can('organisations.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('organisations.manage');
    }

    public function update(User $actor, Organisation $organisation): bool
    {
        return $actor->can('organisations.manage');
    }

    public function delete(User $actor, Organisation $organisation): bool
    {
        return $actor->can('organisations.manage');
    }
}
