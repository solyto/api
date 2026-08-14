<?php

use App\Api\Dashboard\DTOs\DetectionResult;
use App\Api\Dashboard\Enums\QuickAddContentType;

describe('DetectionResult DTO', function () {
    it('stores all constructor values', function () {
        $result = new DetectionResult(
            content: 'https://example.com',
            contentType: QuickAddContentType::Links,
            confidence: 0.95,
            needsConfirmation: false,
            metadata: ['url' => 'https://example.com'],
        );

        expect($result->content)->toBe('https://example.com');
        expect($result->contentType)->toBe(QuickAddContentType::Links);
        expect($result->confidence)->toBe(0.95);
        expect($result->needsConfirmation)->toBeFalse();
        expect($result->metadata)->toBe(['url' => 'https://example.com']);
    });

    it('uses defaults for optional parameters', function () {
        $result = new DetectionResult(
            content: 'text',
            contentType: QuickAddContentType::Note,
            confidence: 0.5,
        );

        expect($result->needsConfirmation)->toBeFalse();
        expect($result->metadata)->toBeNull();
    });
});
