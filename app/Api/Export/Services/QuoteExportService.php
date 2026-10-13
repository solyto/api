<?php

namespace App\Api\Export\Services;

use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Users\Models\User;
use App\Shared\Helpers\CsvHelper;

class QuoteExportService
{
    public function export(User $user, string $path): void
    {
        $quotes = LibraryQuote::forUser($user->id)->with('tags')->get();

        CsvHelper::write($path, ['Quote', 'Author', 'Source', 'Tags'], $quotes->map(fn ($quote) => [
            $quote->quote,
            $quote->author,
            $quote->source,
            $quote->tags->pluck('name')->implode(', '),
        ]));
    }
}
