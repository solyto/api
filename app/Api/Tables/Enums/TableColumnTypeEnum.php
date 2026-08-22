<?php

namespace App\Api\Tables\Enums;

enum TableColumnTypeEnum: string
{
    case TEXT = 'text';
    case NUMBER = 'number';
    case DATE = 'date';
    case CHECKBOX = 'checkbox';
    case URL = 'url';
    case SELECT = 'select';
    case TAGS = 'tags';
    case PICTURE = 'picture';

    public function usesOptions(): bool
    {
        return $this === self::SELECT;
    }
}
