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
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'url' => $this->buildActivationUrl(),
                'expiresAt' => $this->invitation->expires_at,
                'inviter' => $this->invitation->inviter,
            ],
        );
    }

    /**
     * Sign the activation URL on the invitee's tenant subdomain so that the
     * signed-URL signature is valid for the host the user lands on. Without
     * this, ResolveTenant would not bind currentOrganisation, leaving the
     * activated user on the apex domain with no tenant context.
     */
    protected function buildActivationUrl(): string
    {
        $org = $this->invitation->user?->organisation;
        $apex = config('app.apex_domain');
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $host = $org ? "{$org->slug}.{$apex}" : $apex;

        $generator = app('url');
        $forcedRoot = new \ReflectionProperty($generator, 'forcedRoot');
        $previousRoot = $forcedRoot->getValue($generator);

        try {
            $generator->forceRootUrl("{$scheme}://{$host}");

            return URL::temporarySignedRoute(
                'invitation.accept',
                $this->invitation->expires_at,
                ['token' => $this->invitation->token],
            );
        } finally {
            $generator->forceRootUrl($previousRoot);
        }
    }
}
