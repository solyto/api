<?php

namespace App\Shared\Services;

use App\Api\Calendars\Models\CalendarEntry;
use App\Api\CheckIn\Models\CheckIn;
use App\Api\Contacts\Models\Contact;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\WealthField;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Models\LibraryLinkCategory;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\Todos\Models\Todo;
use App\Models\User;
use App\Shared\Models\ExportJob;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ExportService
{
    private const FEATURES = [
        'todos',
        'notes',
        'calendars',
        'contacts',
        'feeds',
        'links',
        'music',
        'books',
        'games',
        'recipes',
        'quotes',
        'checkIn',
        'timeTracking',
        'financesIncome',
        'financesWealth',
    ];

    public function __construct(
        private readonly User $user,
        private readonly ExportJob $job,
    ) {}

    public function export(): string
    {
        $tmpPath = $this->get_storage_path();
        mkdir($tmpPath, 0755, true);

        $features = $this->job->features ?? [];

        foreach ($features as $feature) {
            if (in_array($feature, self::FEATURES) && method_exists($this, $feature)) {
                $this->{$feature}();
            }
        }

        $zipPath = $this->create_zip($tmpPath);
        $this->delete_directory($tmpPath);

        return $zipPath;
    }

    private function create_zip(string $sourceDir): string
    {
        $zipFilename = 'export_'.$this->job->id.'.zip';
        $zipPath = Storage::disk('user_data')->path($this->user->id.'/'.$zipFilename);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $folderName = 'export_'.$this->job->id;

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            $zip->addFile($filePath, $folderName.'/'.$relativePath);
        }

        $zip->close();

        return $zipPath;
    }

    private function delete_directory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($dir);
    }

    public function todos()
    {
        $todos = Todo::forUser($this->user->id)->with(['tags', 'subtasks', 'category'])->get();
        $path = $this->get_storage_path().'/todos.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Title', 'Description', 'Priority', 'Status', 'Effort', 'Progress',
            'Due Date', 'Completed At', 'Is Completed', 'Category', 'Tags',
            'Subtasks', 'Recurrence Frequency', 'Recurrence Interval', 'Recurrence Ends At',
        ]);

        foreach ($todos as $todo) {
            fputcsv($handle, [
                $todo->title,
                $todo->description,
                $todo->priority,
                $todo->status,
                $todo->effort,
                $todo->progress,
                $todo->due_at?->format('Y-m-d'),
                $todo->completed_at?->format('Y-m-d H:i:s'),
                $todo->is_completed ? 'Yes' : 'No',
                $todo->category?->title,
                $todo->tags->pluck('name')->implode(', '),
                $todo->subtasks->map(fn ($s) => ($s->is_completed ? '[x] ' : '[ ] ').$s->title)->implode('; '),
                $todo->recurrence_frequency,
                $todo->recurrence_interval,
                $todo->recurrence_ends_at?->format('Y-m-d'),
            ]);
        }

        fclose($handle);
    }

    public function notes()
    {
        $tmpPath = $this->get_storage_path().'/notes';
        $notes = Note::forUser($this->user->id)->with(['category', 'tags'])->get();
        $allCategories = NoteCategory::forUser($this->user->id)->get()->keyBy('id');

        mkdir($tmpPath, 0755, true);

        $categoryPaths = [];
        $usedNames = [];

        foreach ($notes as $note) {
            $categoryDir = $note->category_id
                ? $this->resolve_category_path($note->category_id, $allCategories, $categoryPaths)
                : null;

            if ($categoryDir) {
                $fullDir = $tmpPath.'/'.$categoryDir;
                if (! is_dir($fullDir)) {
                    mkdir($fullDir, 0755, true);
                }
            }

            $baseName = $this->sanitize_filename($note->title);
            $filename = $this->unique_filename($usedNames, $baseName, $categoryDir).'.md';
            $path = $categoryDir
                ? $tmpPath.'/'.$categoryDir.'/'.$filename
                : $tmpPath.'/'.$filename;

            $content = $note->content;
            if ($note->tags->isNotEmpty()) {
                $content = 'Tags: '.$note->tags->pluck('name')->implode(', ')."\n\n".$content;
            }

            file_put_contents($path, $content);
        }
    }

    public function calendars()
    {
        $entries = CalendarEntry::forUser($this->user->id)->with('calendar')->get();
        $path = $this->get_storage_path().'/calendar.ics';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Solyto//Export//EN',
            'CALSCALE:GREGORIAN',
        ];

        foreach ($entries as $entry) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$entry->id.'@solyto';
            $lines[] = 'SUMMARY:'.$this->escape_ics($entry->title);
            $lines[] = 'DTSTART:'.$this->format_ics_date($entry->start_date, $entry->is_all_day);
            $lines[] = 'DTEND:'.$this->format_ics_date($entry->end_date, $entry->is_all_day);

            if ($entry->description) {
                $lines[] = 'DESCRIPTION:'.$this->escape_ics($entry->description);
            }

            if ($entry->location) {
                $lines[] = 'LOCATION:'.$this->escape_ics($entry->location);
            }

            if ($entry->recurrence_rule) {
                $lines[] = 'RRULE:'.$entry->recurrence_rule;
            }

            if ($entry->calendar) {
                $lines[] = 'X-WR-CALNAME:'.$this->escape_ics($entry->calendar->title);
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        file_put_contents($path, implode("\r\n", $lines));
    }

    public function contacts()
    {
        $contacts = Contact::forUser($this->user->id)->get();
        $path = $this->get_storage_path().'/contacts.vcf';

        $lines = [];

        foreach ($contacts as $contact) {
            $lines[] = 'BEGIN:VCARD';
            $lines[] = 'VERSION:3.0';
            $lines[] = 'FN:'.trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

            if ($contact->last_name || $contact->first_name) {
                $lines[] = 'N:'.($contact->last_name ?? '').';'.($contact->first_name ?? '').';'.($contact->middle_name ?? '').';;';
            }

            if ($contact->email) {
                $emails = is_array($contact->email) ? $contact->email : [$contact->email];
                foreach ($emails as $email) {
                    $lines[] = 'EMAIL:'.$email;
                }
            }

            if ($contact->phone) {
                $phones = is_array($contact->phone) ? $contact->phone : [$contact->phone];
                foreach ($phones as $phone) {
                    $lines[] = 'TEL:'.$phone;
                }
            }

            if ($contact->organization) {
                $lines[] = 'ORG:'.$this->escape_vcard($contact->organization);
            }

            if ($contact->address || $contact->city || $contact->country) {
                $lines[] = 'ADR;TYPE=HOME:;;'
                    .($contact->address ?? '').';'
                    .($contact->city ?? '').';;'
                    .($contact->postal_code ?? '').';'
                    .($contact->country ?? '');
            }

            if ($contact->note) {
                $lines[] = 'NOTE:'.$this->escape_vcard($contact->note);
            }

            $lines[] = 'END:VCARD';
        }

        file_put_contents($path, implode("\r\n", $lines));
    }

    public function music()
    {
        $items = LibraryMusic::forUser($this->user->id)->with('genres')->get();
        $path = $this->get_storage_path().'/music.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Title', 'Artist', 'Type', 'Format', 'Condition', 'Rating',
            'Publication Year', 'Acquired Where', 'Genres', 'Wishlist', 'Link',
        ]);

        foreach ($items as $item) {
            fputcsv($handle, [
                $item->title,
                $item->artist,
                $item->type,
                $item->format,
                $item->condition,
                $item->rating,
                $item->publication_year,
                $item->acquired_where,
                $item->genres->pluck('title')->implode(', '),
                $item->wishlist ? 'Yes' : 'No',
                $item->link,
            ]);
        }

        fclose($handle);
    }

    public function books()
    {
        $books = LibraryBook::forUser($this->user->id)->with(['tags', 'genres'])->get();
        $path = $this->get_storage_path().'/books.csv';

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

    public function games()
    {
        $games = LibraryGame::forUser($this->user->id)->with(['tags', 'genres'])->get();
        $path = $this->get_storage_path().'/games.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Title', 'Rating', 'Publication Year', 'Platform', 'Developer',
            'Publisher', 'Playtime Hours', 'Completed', 'Wishlist',
            'Started At', 'Finished At', 'Genres', 'Tags',
        ]);

        foreach ($games as $game) {
            fputcsv($handle, [
                $game->title,
                $game->rating,
                $game->publication_year,
                $game->platform,
                $game->developer,
                $game->publisher,
                $game->playtime_hours,
                $game->completed ? 'Yes' : 'No',
                $game->wishlist ? 'Yes' : 'No',
                $game->started_at?->format('Y-m-d'),
                $game->finished_at?->format('Y-m-d'),
                $game->genres->pluck('title')->implode(', '),
                $game->tags->pluck('name')->implode(', '),
            ]);
        }

        fclose($handle);
    }

    public function recipes()
    {
        $recipes = LibraryRecipe::forUser($this->user->id)->get();
        $path = $this->get_storage_path().'/recipes.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Title', 'Description', 'Ingredients', 'Type', 'Time To Make', 'Rating', 'Link',
        ]);

        foreach ($recipes as $recipe) {
            fputcsv($handle, [
                $recipe->title,
                $recipe->description,
                $recipe->ingredients,
                $recipe->type,
                $recipe->time_to_make,
                $recipe->rating,
                $recipe->link,
            ]);
        }

        fclose($handle);
    }

    public function quotes()
    {
        $quotes = LibraryQuote::forUser($this->user->id)->with('tags')->get();
        $path = $this->get_storage_path().'/quotes.csv';

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

    public function links()
    {
        $links = LibraryLink::forUser($this->user->id)->with(['tags', 'category'])->get();
        $categories = LibraryLinkCategory::forUser($this->user->id)->get();
        $path = $this->get_storage_path().'/links.html';

        $html = '<!DOCTYPE NETSCAPE-Bookmark-file-1>'."\n"
            .'<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=UTF-8">'."\n"
            .'<TITLE>Bookmarks</TITLE>'."\n"
            .'<H1>Bookmarks</H1>'."\n"
            .'<DL><p>'."\n";

        $grouped = $links->groupBy('category_id');

        foreach ($categories as $category) {
            $categoryLinks = $grouped->get($category->id, collect());
            if ($categoryLinks->isEmpty()) {
                continue;
            }

            $html .= '    <DT><H3>'.htmlspecialchars($category->title).'</H3>'."\n"
                .'    <DL><p>'."\n";

            foreach ($categoryLinks as $link) {
                $tags = $link->tags->pluck('name')->implode(',');
                $html .= '        <DT><A HREF="'.htmlspecialchars($link->url).'"'
                    .($tags ? ' TAGS="'.htmlspecialchars($tags).'"' : '')
                    .($link->is_favorite ? ' ICON="★"' : '')
                    .'>'.htmlspecialchars($link->title).'</A>'."\n";
            }

            $html .= '    </DL><p>'."\n";
        }

        $uncategorized = $grouped->get(null, collect());
        foreach ($uncategorized as $link) {
            $tags = $link->tags->pluck('name')->implode(',');
            $html .= '    <DT><A HREF="'.htmlspecialchars($link->url).'"'
                .($tags ? ' TAGS="'.htmlspecialchars($tags).'"' : '')
                .($link->is_favorite ? ' ICON="★"' : '')
                .'>'.htmlspecialchars($link->title).'</A>'."\n";
        }

        $html .= '</DL><p>'."\n";

        file_put_contents($path, $html);
    }

    public function checkIn()
    {
        $checkIns = CheckIn::forUser($this->user->id)->orderBy('date')->get();
        $path = $this->get_storage_path().'/check_ins.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Date', 'Mood', 'Water', 'Sports', 'Sleep', 'Dreams',
            'Work', 'Food Quality', 'Food Amount', 'Menstruation', 'Alcohol', 'Smoking',
        ]);

        foreach ($checkIns as $checkIn) {
            fputcsv($handle, [
                $checkIn->date?->format('Y-m-d'),
                $checkIn->mood,
                $checkIn->water,
                $checkIn->sports,
                $checkIn->sleep,
                $checkIn->dreams,
                $checkIn->work,
                $checkIn->food_quality,
                $checkIn->food_amount,
                $checkIn->menstruation,
                $checkIn->alcohol,
                $checkIn->smoking,
            ]);
        }

        fclose($handle);
    }

    public function timeTracking()
    {
        $entries = TimeTrackingEntry::forUser($this->user->id)->with('project')->get();
        $path = $this->get_storage_path().'/time_tracking.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'Description', 'Project', 'Started At', 'Stopped At',
            'Duration (minutes)', 'Has Exact Times',
        ]);

        foreach ($entries as $entry) {
            fputcsv($handle, [
                $entry->description,
                $entry->project?->title,
                $entry->started_at,
                $entry->stopped_at,
                $entry->duration_minutes,
                $entry->has_exact_times ? 'Yes' : 'No',
            ]);
        }

        fclose($handle);
    }

    public function feeds()
    {
        $subscriptions = FeedSubscription::where('user_id', $this->user->id)->with('feed')->get();
        $path = $this->get_storage_path().'/feeds.opml';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<opml version="1.0">'."\n"
            .'    <head>'."\n"
            .'        <title>Solyto Feeds Export</title>'."\n"
            .'    </head>'."\n"
            .'    <body>'."\n";

        foreach ($subscriptions as $sub) {
            $title = htmlspecialchars($sub->title ?? $sub->feed?->title ?? '');
            $url = htmlspecialchars($sub->feed?->url ?? '');
            $xml .= '        <outline type="rss" text="'.$title.'" title="'.$title.'" xmlUrl="'.$url.'" />'."\n";
        }

        $xml .= '    </body>'."\n"
            .'</opml>';

        file_put_contents($path, $xml);
    }

    public function financesIncome()
    {
        $incomes = Budget::forUser($this->user->id)->where('type', 'income')->get();
        $path = $this->get_storage_path().'/finances_income.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, ['Title', 'Value']);

        foreach ($incomes as $income) {
            fputcsv($handle, [
                $income->title,
                $income->value,
            ]);
        }

        fclose($handle);
    }

    public function financesWealth()
    {
        $fields = WealthField::forUser($this->user->id)->with('values')->get();
        $path = $this->get_storage_path().'/finances_wealth.csv';

        $handle = fopen($path, 'w');
        fputcsv($handle, ['Field', 'Date', 'Value']);

        foreach ($fields as $field) {
            foreach ($field->values as $value) {
                fputcsv($handle, [
                    $field->title,
                    $value->date?->format('Y-m-d'),
                    $value->value,
                ]);
            }
        }

        fclose($handle);
    }

    private function resolve_category_path(int $categoryId, $allCategories, array &$cache): string
    {
        if (isset($cache[$categoryId])) {
            return $cache[$categoryId];
        }

        $segments = [];
        $id = $categoryId;

        while ($id && $allCategories->has($id)) {
            $cat = $allCategories[$id];
            array_unshift($segments, $this->sanitize_filename($cat->title));
            $id = $cat->parent_id;
        }

        return $cache[$categoryId] = implode('/', $segments);
    }

    private function sanitize_filename(string $name): string
    {
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $name);

        return trim($name, '. ');
    }

    private function unique_filename(array &$usedNames, string $baseName, ?string $categoryDir): string
    {
        $key = ($categoryDir ?? '__root__').'/'.$baseName;
        if (! isset($usedNames[$key])) {
            $usedNames[$key] = 0;

            return $baseName;
        }

        $usedNames[$key]++;

        return $baseName.' ('.$usedNames[$key].')';
    }

    private function escape_ics(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    private function format_ics_date(int $timestamp, bool $isAllDay): string
    {
        if ($isAllDay) {
            return 'VALUE=DATE:'.gmdate('Ymd', $timestamp);
        }

        return gmdate('Ymd\THis\Z', $timestamp);
    }

    private function escape_vcard(string $text): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
    }

    private function get_storage_path(): string
    {
        return Storage::disk('user_data')->path($this->user->id.'/exports/'.$this->job->id);
    }
}
