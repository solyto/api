<?php

namespace App\Bots\DTOs;

class Keyboard
{
    private array $rows;
    private array $buttons;

    public static function make(): self
    {
        return new self();
    }

    public function withRow(array $buttons): self
    {
        $this->rows[] = $buttons;
        return $this;
    }

    public static function button(string $text, ?string $url = null): array
    {
        $button = ['text' => $text];

        if ($url) {
            $button['url'] = $url;
        }

        return $button;
    }

    public function asArray(): array
    {
        return $this->rows;
    }
}
