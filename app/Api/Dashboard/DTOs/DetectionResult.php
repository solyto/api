<?php

namespace App\Api\Dashboard\DTOs;

use App\Api\Dashboard\Enums\QuickAddContentType;

final readonly class DetectionResult
{
    public function __construct(
        public string $url,
        public QuickAddContentType $contentType,
        public float $confidence,
        public bool $needsConfirmation = false,
        public ?array $metadata = null,
    ) {}
}
