<?php

namespace App\Shared\Notifications\Channels;

class TelegramMessage
{
    protected string $content = '';

    public static function create(): self
    {
        return new self();
    }

    public function line(string $text): self
    {
        $this->content .= $text . "\n";
        return $this;
    }

    public function content(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function url(string $url, ?string $label = null): self
    {
        $this->content .= $label ? "{$label}: {$url}\n" : "{$url}\n";
        return $this;
    }

    public function getContent(): string
    {
        return trim($this->content);
    }
}
