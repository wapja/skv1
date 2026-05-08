<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Uitnodiging voor :app', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        $url = URL::temporarySignedRoute(
            'invitation.accept',
            $this->invitation->expires_at,
            ['token' => $this->invitation->token],
        );

        return new Content(
            markdown: 'mail.invitation',
            with: [
                'url' => $url,
                'expiresAt' => $this->invitation->expires_at,
                'inviter' => $this->invitation->inviter,
            ],
        );
    }
}
