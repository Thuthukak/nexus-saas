<?php

declare(strict_types=1);

namespace App\Platform\Onboarding\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent from the platform's shared, pre-verified sending domain (the tenant has no email
 * integration yet). Queued, so an SMTP outage can never fail or slow a signup.
 * Contains the workspace address only: never a password and never the one-time sign-in link.
 */
final class WelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $adminName,
        public readonly string $workspaceUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.$this->companyName.' workspace is ready');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.welcome');
    }
}
