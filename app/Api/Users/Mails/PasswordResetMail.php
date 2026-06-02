<?php

namespace App\Api\Users\Mails;

use App\Api\Users\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly User $user,
        private readonly string $token,
        private readonly string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your Solyto password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mails.password-reset',
        )->with([
            'name'  => $this->user->name,
            'token' => $this->token,
            'email' => urlencode($this->email),
        ]);
    }

    public function attachments(): array
    {
        return [];
    }
}
