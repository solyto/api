<?php

namespace App\Shared\Notifications\Channels;

class NtfyMessage
{
    protected string $title = '';
    protected string $message = '';

    public static function create(): self
    {
        return new self();
    }

    public function title(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function message(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function toArray(string $topic): array
    {
        return [
            'topic'   => $topic,
            'title'   => $this->title,
            'message' => $this->message,
        ];
    }
}
