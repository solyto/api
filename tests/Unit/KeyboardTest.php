<?php

use App\Bots\DTOs\Keyboard;

covers(Keyboard::class);

describe('Keyboard DTO', function () {
    it('creates an empty keyboard', function () {
        $keyboard = Keyboard::make()->withRow([]);

        expect($keyboard->asArray())->toBe([[]]);
    });

    it('builds rows of buttons', function () {
        $keyboard = Keyboard::make()
            ->withRow([Keyboard::button('One'), Keyboard::button('Two')])
            ->withRow([Keyboard::button('Three')]);

        expect($keyboard->asArray())->toBe([
            [['text' => 'One'], ['text' => 'Two']],
            [['text' => 'Three']],
        ]);
    });

    it('creates a plain button', function () {
        expect(Keyboard::button('Hello'))->toBe(['text' => 'Hello']);
    });

    it('creates a button with a url', function () {
        expect(Keyboard::button('Open', 'https://example.com'))
            ->toBe(['text' => 'Open', 'url' => 'https://example.com']);
    });

    it('make() returns a new instance', function () {
        expect(Keyboard::make())->toBeInstanceOf(Keyboard::class);
        expect(Keyboard::make())->not->toBe(Keyboard::make());
    });
});
