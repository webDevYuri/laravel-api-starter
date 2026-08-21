<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $resetUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').' password reset');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset', with: [
            'resetUrl' => $this->resetUrl,
            'expires' => config('auth.passwords.users.expire'),
        ]);
    }
}
