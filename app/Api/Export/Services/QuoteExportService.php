<?php

namespace App\Api\Export\Services;

use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Users\Models\User;

class QuoteExportService
{
    public function export(User $user, string $path): void
    {
        $quotes = LibraryQuote::forUser($user->id)->with('tags')->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, ['Quote', 'Author', 'Source', 'Tags']);

        foreach ($quotes as $quote) {
            fputcsv($handle, [
                $quote->quote,
                $quote->author,
                $quote->source,
                $quote->tags->pluck('name')->implode(', '),
            ]);
        }

        fclose($handle);
    }
}
