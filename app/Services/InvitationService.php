<?php

namespace App\Services;

use App\Exceptions\Invitation\InvitationAlreadyAccepted;
use App\Exceptions\Invitation\InvitationCancelled;
use App\Exceptions\Invitation\InvitationExpired;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationService
{
    public function invite(
        string $firstName,
        ?string $middleName,
        string $lastName,
        string $email,
        string $locale,
        array $roles,
        User $invitedBy,
        int $organisationId,
    ): Invitation {
        return DB::transaction(function () use ($firstName, $middleName, $lastName, $email, $locale, $roles, $invitedBy, $organisationId) {
            $user = User::create([
                'organisation_id' => $organisationId,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'email' => $email,
                'start_date' => now()->toDateString(),
                'locale' => $locale,
                'status' => 'pending_activation',
            ]);

            app(UserRoleSyncer::class)->sync($user, $roles, $organisationId);

            $invitation = Invitation::create([
                'user_id' => $user->id,
                'invited_by' => $invitedBy->id,
                'token' => Str::random(64),
                'expires_at' => now()->addDays(7),
            ]);

            Mail::to($email)->queue(new InvitationMail($invitation));

            activity('invitations')
                ->performedOn($invitation)
                ->causedBy($invitedBy)
                ->withProperties(['email' => $email, 'roles' => $roles])
                ->log('sent');

            $invitation = $invitation->fresh();
            $invitation->setRelation(
                'user',
                User::withoutTenantScope()->findOrFail($invitation->user_id)
            );

            return $invitation;
        });
    }

    public function accept(string $token, string $password, ?string $totpSecret = null): User
    {
        return DB::transaction(function () use ($token, $password, $totpSecret) {
            $invitation = Invitation::where('token', $token)->firstOrFail();

            $user = User::withoutTenantScope()
                ->withTrashed()
                ->findOrFail($invitation->user_id);

            if ($user->trashed()) {
                throw new InvitationCancelled('Invitation has been cancelled.');
            }

            if ($invitation->accepted_at !== null) {
                throw new InvitationAlreadyAccepted('Invitation has already been accepted.');
            }

            if ($invitation->expires_at->isPast()) {
                throw new InvitationExpired('Invitation token has expired.');
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'status' => 'active',
                'activated_at' => now(),
                'activation_token' => null,
                'activation_expires_at' => null,
            ]);

            if ($totpSecret !== null) {
                $user->forceFill([
                    'two_factor_secret' => $totpSecret,
                    'two_factor_enabled_at' => now(),
                ]);
            }

            $user->save();

            $invitation->update(['accepted_at' => now()]);

            activity('invitations')
                ->performedOn($invitation)
                ->causedBy($user)
                ->log('accepted');

            return $user->fresh();
        });
    }

    public function resendReminder(Invitation $invitation, User $actor): void
    {
        if ($invitation->accepted_at !== null) {
            throw new InvitationAlreadyAccepted('Invitation has already been accepted.');
        }

        $user = User::withoutTenantScope()
            ->withTrashed()
            ->findOrFail($invitation->user_id);

        if ($user->trashed()) {
            throw new InvitationCancelled('Invitation has been cancelled.');
        }

        if ($invitation->expires_at->isPast()) {
            throw new InvitationExpired('Invitation token has expired.');
        }

        DB::transaction(function () use ($invitation, $actor, $user) {
            Mail::to($user->email)->queue(new InvitationMail($invitation));

            $invitation->update([
                'reminder_sent_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);

            activity('invitations')
                ->performedOn($invitation)
                ->causedBy($actor)
                ->log('reminder_sent');
        });
    }

    public function cancel(Invitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($invitation, $actor) {
            $user = User::withoutTenantScope()
                ->withTrashed()
                ->findOrFail($invitation->user_id);

            if (! $user->trashed()) {
                $user->delete();
            }

            activity('invitations')
                ->performedOn($invitation)
                ->causedBy($actor)
                ->log('cancelled');
        });
    }

    public function purgeExpired(): int
    {
        return Invitation::query()
            ->whereNull('accepted_at')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
