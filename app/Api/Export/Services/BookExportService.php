<?php

namespace App\Api\Export\Services;

use App\Api\Libraries\Models\LibraryBook;
use App\Api\Users\Models\User;

class BookExportService
{
    public function export(User $user, string $path): void
    {
        $books = LibraryBook::forUser($user->id)->with(['tags', 'genres'])->get();

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Title', 'Author', 'My Rating', 'Number of Pages', 'Year Published',
            'Date Read', 'Date Added', 'Bookshelves', 'My Review', 'Exclusive Shelf', 'Tags',
        ]);

        foreach ($books as $book) {
            fputcsv($handle, [
                $book->title,
                $book->author,
                $book->rating,
                $book->pages,
                $book->publication_year,
                $book->finished_at?->format('Y/m/d'),
                $book->created_at->format('Y/m/d'),
                $book->genres->pluck('title')->implode(', '),
                $book->summary,
                $book->wishlist ? 'to-read' : 'read',
                $book->tags->pluck('name')->implode(', '),
            ]);
        }

        fclose($handle);
    }
}
