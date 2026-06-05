<?php

namespace App\Api\Users\Mails;

use App\Api\Users\Models\User;
use App\Api\Users\Models\VerificationToken;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserVerification extends Mailable
{
    use Queueable, SerializesModels;

    private readonly User $user;
    private readonly VerificationToken $verificationToken;
    private readonly string $platform;
    private readonly string $language;

    public function __construct(User $user, VerificationToken $verificationToken, string $platform = 'web', string $language = 'en')
    {
        $this->user = $user;
        $this->verificationToken = $verificationToken;
        $this->platform = $platform;
        $this->language = $language;
        $this->locale = $language;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.verification_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mails.user-verification',
        )->with([
            'user_id' => $this->verificationToken->user_id,
            'name' => $this->user->name,
            'token' => $this->verificationToken->token,
            'platform' => $this->platform,
        ]);
    }

    public function attachments(): array
    {
        return [];
    }
}
