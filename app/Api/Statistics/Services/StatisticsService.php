<?php

namespace App\Api\Statistics\Services;

use App\Api\CheckIn\Models\CheckIn;
use App\Api\Feeds\Models\FeedSubscription;
use App\Api\Finances\Models\Budget;
use App\Api\Finances\Models\WealthValue;
use App\Api\Libraries\Models\LibraryBook;
use App\Api\Libraries\Models\LibraryGame;
use App\Api\Libraries\Models\LibraryLink;
use App\Api\Libraries\Models\LibraryMovie;
use App\Api\Libraries\Models\LibraryMusic;
use App\Api\Libraries\Models\LibraryQuote;
use App\Api\Libraries\Models\LibraryRecipe;
use App\Api\Notes\Models\Note;
use App\Api\Notes\Models\NoteCategory;
use App\Api\Tags\Models\Tag;
use App\Api\TimeTracking\Models\TimeTrackingCategory;
use App\Api\TimeTracking\Models\TimeTrackingEntry;
use App\Api\TimeTracking\Models\TimeTrackingProject;
use App\Api\Todos\Models\Todo;
use App\Api\Todos\Models\TodoCategory;
use App\Api\Todos\Models\TodoWorkspace;
use App\Api\Users\Models\Friend;
use App\Api\Users\Models\User;
use App\Shared\Models\AiUsage;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    public function overview(): array
    {
        $pgsql = DB::connection('pgsql');

        return [
            'users' => User::count(),
            'confirmed_users' => User::whereNotNull('email_verified_at')->count(),
            'todos' => Todo::count(),
            'todo_categories' => TodoCategory::count(),
            'todo_workspaces' => TodoWorkspace::count(),
            'tags' => Tag::count(),
            'notes' => Note::count(),
            'note_folders' => NoteCategory::count(),
            'time_tracking_entries' => TimeTrackingEntry::count(),
            'time_tracking_categories' => TimeTrackingCategory::count(),
            'time_tracking_projects' => TimeTrackingProject::count(),
            'calendars' => $pgsql->table('calendars')->count(),
            'calendar_events' => $pgsql->table('calendarobjects')->count(),
            'address_books' => $pgsql->table('addressbooks')->count(),
            'contacts' => $pgsql->table('cards')->count(),
            'friends' => Friend::count(),
            'albums' => LibraryMusic::count(),
            'books' => LibraryBook::count(),
            'movies' => LibraryMovie::count(),
            'recipes' => LibraryRecipe::count(),
            'links' => LibraryLink::count(),
            'quotes' => LibraryQuote::count(),
            'library_games' => LibraryGame::count(),
            'feed_subscriptions' => FeedSubscription::count(),
            'income_entries' => Budget::count(),
            'wealth_entries' => WealthValue::count(),
            'check_in_entries' => CheckIn::count(),
            'ui_notifications' => DB::table('notifications')->count(),
            'ai_tokens' => AiUsage::sum('total_tokens'),
        ];
    }
}
