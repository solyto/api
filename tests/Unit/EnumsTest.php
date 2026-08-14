<?php

use App\Api\Dashboard\Enums\QuickAddContentType;
use App\Bots\Messages\SolytoMessage;
use App\Shared\Enums\AiUsageFeatureEnum;
use App\Shared\Enums\AuthPlatformEnum;

describe('AuthPlatformEnum', function () {
    it('has the expected cases', function () {
        expect(AuthPlatformEnum::WEB->value)->toBe('web');
        expect(AuthPlatformEnum::MOBILE->value)->toBe('mobile');
        expect(AuthPlatformEnum::DESKTOP->value)->toBe('desktop');
    });

    it('returns 7 days for web tokens', function () {
        expect(AuthPlatformEnum::WEB->tokenExpiryDays())->toBe(7);
    });

    it('returns 90 days for mobile and desktop tokens', function () {
        expect(AuthPlatformEnum::MOBILE->tokenExpiryDays())->toBe(90);
        expect(AuthPlatformEnum::DESKTOP->tokenExpiryDays())->toBe(90);
    });

    it('can be instantiated from a value', function () {
        expect(AuthPlatformEnum::from('mobile'))->toBe(AuthPlatformEnum::MOBILE);
    });
});

describe('AiUsageFeatureEnum', function () {
    it('has the expected cases', function () {
        expect(AiUsageFeatureEnum::ASSISTANT_CHAT->value)->toBe('assistant_chat');
        expect(AiUsageFeatureEnum::LIBRARY_RECOMMENDER->value)->toBe('library_recommender');
    });
});

describe('QuickAddContentType', function () {
    it('has the expected cases', function () {
        expect(QuickAddContentType::Music->value)->toBe('music');
        expect(QuickAddContentType::Books->value)->toBe('books');
        expect(QuickAddContentType::Movies->value)->toBe('movies');
        expect(QuickAddContentType::Games->value)->toBe('games');
        expect(QuickAddContentType::Links->value)->toBe('links');
        expect(QuickAddContentType::Recipes->value)->toBe('recipes');
        expect(QuickAddContentType::Plants->value)->toBe('plants');
        expect(QuickAddContentType::Quotes->value)->toBe('quotes');
        expect(QuickAddContentType::Todo->value)->toBe('todo');
        expect(QuickAddContentType::Note->value)->toBe('note');
        expect(QuickAddContentType::Feed->value)->toBe('feed');
        expect(QuickAddContentType::Clipboard->value)->toBe('clipboard');
    });
});

describe('SolytoMessage', function () {
    it('covers all bot messages', function () {
        expect(SolytoMessage::WELCOME->value)->toBe('bot.welcome');
        expect(SolytoMessage::INVALID_TOKEN->value)->toBe('bot.invalid_token');
        expect(SolytoMessage::CHOOSE_TYPE->value)->toBe('bot.choose_type');
        expect(SolytoMessage::ERROR->value)->toBe('bot.error');
        expect(SolytoMessage::CANCELLED->value)->toBe('bot.cancelled');
    });

    it('trans() resolves through the translator', function () {
        expect(SolytoMessage::WELCOME->trans())->toBeString();
        expect(SolytoMessage::WELCOME->trans())->not->toBeEmpty();
    });
});
