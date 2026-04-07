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

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, VerificationToken $verificationToken)
    {
        $this->user = $user;
        $this->verificationToken = $verificationToken;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your mail address',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mails.user-verification',
        )->with([
            'user_id' => $this->verificationToken->user_id,
            'name' => $this->user->name,
            'token' => $this->verificationToken->token,
        ]);
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
