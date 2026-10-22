<?php

declare(strict_types=1);

namespace Modules\Core\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $subjectLine, public readonly ?string $bodyText)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification');
    }
}
