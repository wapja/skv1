<?php

namespace App\Services;

use App\Exceptions\Impersonation\CannotImpersonateSuperAdmin;
use App\Exceptions\Impersonation\ImpersonationDepthExceeded;
use App\Exceptions\Impersonation\ImpersonationNotPermitted;
use App\Models\User;
use Lab404\Impersonate\Services\ImpersonateManager;

class ImpersonationGuard
{
    public function start(User $actor, User $target, string $reason): void
    {
        $reasonLength = mb_strlen($reason);
        if ($reasonLength < 1 || $reasonLength > 500) {
            throw new ImpersonationNotPermitted('Reason must be between 1 and 500 characters.');
        }

        if ($target->isSuperAdmin()) {
            throw new CannotImpersonateSuperAdmin('Super-admin accounts cannot be impersonated.');
        }

        if (app(ImpersonateManager::class)->isImpersonating()) {
            throw new ImpersonationDepthExceeded('Cannot start impersonation while already impersonating.');
        }

        $this->assertActorMayImpersonate($actor, $target);

        $actor->impersonate($target);

        activity('impersonation')
            ->performedOn($target)
            ->causedBy($actor)
            ->withProperties([
                'reason' => $reason,
                'impersonated_as' => $target->email,
            ])
            ->log('started');
    }

    public function stop(): void
    {
        $current = auth()->user();
        if ($current && method_exists($current, 'leaveImpersonation')) {
            $current->leaveImpersonation();
        }
    }

    protected function assertActorMayImpersonate(User $actor, User $target): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if (! $actor->hasRole('organisation_admin')) {
            throw new ImpersonationNotPermitted('Only super-admins or organisation admins can impersonate.');
        }

        if ($actor->organisation_id !== $target->organisation_id) {
            throw new ImpersonationNotPermitted('Cannot impersonate users in another organisation.');
        }

        if ($target->hasRole('organisation_admin')) {
            throw new ImpersonationNotPermitted('Cannot impersonate another organisation admin.');
        }
    }
}
