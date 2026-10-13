<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\User;
use App\Api\Users\Services\UserService;
use Illuminate\Console\Command;

class DeleteUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:users:delete {email : Email address of the user to delete}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently delete a user with confirmation and remove all their data (DAV, app data, files, cache)';

    /**
     * Execute the console command.
     */
    public function handle(UserService $users): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("No user found with email: {$email}");

            return self::FAILURE;
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Role'],
            [[$user->id, $user->name, $user->email, $user->role]],
        );

        if (!$this->confirm("Permanently delete {$user->name} and ALL their data? This cannot be undone")) {
            $this->info('Deletion cancelled. Nothing was deleted.');

            return self::SUCCESS;
        }

        $summary = $users->destroy($user);

        $this->info("DAV: deleted {$summary['owned_calendars']} owned calendar(s), removed {$summary['shared_instances']} shared instance(s), {$summary['address_books']} address book(s), {$summary['calendar_subscriptions']} subscription(s), {$summary['scheduling_objects']} scheduling object(s), {$summary['property_rows']} property row(s), principal: {$this->yesNo($summary['principal'])}");
        $this->info("App: anonymised {$summary['dev_requests_anonymised']} dev request(s), deleted {$summary['auth_tokens']} auth token(s), {$summary['notifications']} notification(s), {$summary['push_subscriptions']} push subscription(s), {$summary['verification_tokens']} verification token(s), {$summary['password_reset_tokens']} password reset token(s), {$summary['sessions']} session(s)");
        $this->info('User row deleted (cascades remove todos, notes, contacts, feeds, libraries, finances, time tracking, and the rest).');
        $this->info('Files: deleted user_data directory ('.$this->yesNo($summary['user_data_files']).'), private_user_data directory ('.$this->yesNo($summary['private_user_data_files']).')');
        $this->info("Cache: flushed {$summary['cache_keys_flushed']} user-scoped key(s).");

        $this->info("User {$email} deleted successfully.");

        return self::SUCCESS;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
