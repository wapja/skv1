<?php

namespace App\Livewire\Invitations;

use App\Models\Invitation;
use App\Services\InvitationService;
use Livewire\Attributes\On;
use Livewire\Component;

class PendingList extends Component
{
    public function pending()
    {
        return Invitation::query()
            ->whereNull('accepted_at')
            ->whereHas('user', fn ($q) => $q->whereNull('deleted_at'))
            ->with(['user', 'inviter'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function cancel(int $invitationId, InvitationService $service): void
    {
        abort_unless(auth()->user()?->can('invitations.cancel'), 403);

        $invitation = Invitation::findOrFail($invitationId);
        $service->cancel($invitation, auth()->user());

        $this->dispatch('invitation-cancelled');
    }

    public function resend(int $invitationId, InvitationService $service): void
    {
        abort_unless(auth()->user()?->can('invitations.send'), 403);

        $invitation = Invitation::findOrFail($invitationId);
        $service->resendReminder($invitation, auth()->user());
    }

    #[On('invitation-sent')]
    #[On('invitation-cancelled')]
    public function refresh(): void
    {
        // tells Livewire to re-render; pending() recomputes on render()
    }

    public function render()
    {
        return view('livewire.invitations.pending-list', ['pending' => $this->pending()]);
    }
}
