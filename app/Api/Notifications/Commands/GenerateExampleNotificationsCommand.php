<?php

namespace App\Api\Notifications\Commands;

use App\Api\Calendars\Notifications\CalendarShareNotification;
use App\Api\Libraries\Notifications\BookReleaseNotification;
use App\Api\Users\Models\User;
use App\Api\Users\Notifications\FriendRequestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

use function Laravel\Prompts\search;

class GenerateExampleNotificationsCommand extends Command
{
    protected $signature = 'app:notifications:generate-examples {email? : Look up the user by email instead of the interactive search}';

    protected $description = 'Search a user and generate 3 example notifications of different types (database channel only)';

    public function handle(): void
    {
        $user = $this->argument('email') !== null
            ? $this->userFromEmail((string) $this->argument('email'))
            : $this->userFromSearch();

        if ($user === null) {
            return;
        }

        $examples = [
            new FriendRequestNotification('Alex Demo'),
            new CalendarShareNotification('Demo Calendar', 'Sam Demo'),
            new BookReleaseNotification('Robin Demo', 'The Demo Chronicles'),
        ];

        // Database channel only: the examples land in the user's notification
        // list without sending real mail, web push or Telegram messages and
        // regardless of the user's notification settings.
        foreach ($examples as $notification) {
            Notification::sendNow([$user], $notification, ['database']);
        }

        $this->info('Generated '.count($examples).' example notifications for '.$user->name.' ('.$user->email.'):');

        $this->table(
            ['Type', 'Title', 'Body'],
            $user->notifications()
                ->orderByDesc('created_at')
                ->limit(count($examples))
                ->get()
                ->map(fn ($notification) => [
                    $notification->type,
                    $notification->data['title'] ?? '-',
                    $notification->data['body'] ?? '-',
                ]),
        );
    }

    protected function userFromEmail(string $email): ?User
    {
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("User with email {$email} not found.");
        }

        return $user;
    }

    protected function userFromSearch(): ?User
    {
        $userId = search(
            label: 'Search user (name, email or ID)',
            options: function (string $value) {
                if ($value === '') {
                    return [];
                }

                return User::where('name', 'like', "%{$value}%")
                    ->orWhere('email', 'like', "%{$value}%")
                    ->orWhere('id', 'like', "%{$value}%")
                    ->orderBy('name')
                    ->limit(10)
                    ->get()
                    ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->email})"])
                    ->all();
            },
            placeholder: 'Type to search...',
        );

        if ($userId === null) {
            $this->warn('No user selected.');

            return null;
        }

        return User::find($userId);
    }
}
