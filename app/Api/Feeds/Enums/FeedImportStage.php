<?php

namespace App\Api\Feeds\Enums;

enum FeedImportStage: string
{
    case Parsed = 'parsed';
    case Running = 'running';
    case Finished = 'finished';
}
