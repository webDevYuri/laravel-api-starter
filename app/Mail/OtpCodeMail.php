<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $purpose = 'verification',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').' verification code');
    }

    public function content(): Content
    {
        [$heading, $intro] = match ($this->purpose) {
            'login' => ['Sign in to '.config('app.name'), 'Enter the code below to sign in:'],
            default => ['Verify your email', 'Enter the code below to continue:'],
        };

        return new Content(view: 'emails.otp-code', with: [
            'code' => $this->code,
            'expires' => config('otp.expires'),
            'heading' => $heading,
            'intro' => $intro,
        ]);
    }
}
