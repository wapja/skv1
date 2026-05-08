<?php

namespace App\Policies;

use App\Models\Organisation;
use App\Models\User;

class OrganisationPolicy
{
    public function viewAny(User $actor): bool
    {
        return false;
    }

    public function view(User $actor, Organisation $organisation): bool
    {
        return $actor->organisation_id === $organisation->id
            && $actor->can('organisations.view');
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Organisation $organisation): bool
    {
        return false;
    }

    public function delete(User $actor, Organisation $organisation): bool
    {
        return false;
    }
}
