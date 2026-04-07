<?php

namespace App\Shared\Enums;

enum AiUsageFeatureEnum: string
{
    case ASSISTANT_CHAT = 'assistant_chat';
    case LIBRARY_RECOMMENDER = 'library_recommender';
}
