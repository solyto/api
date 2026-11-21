<?php

namespace App\Api\Alerts\Commands;

use App\Api\Alerts\Services\AlertDispatcher;
use App\Api\Users\Models\User;
use Illuminate\Console\Command;

class SendAlertsCommand extends Command
{
    protected $signature = 'app:send-alerts {--user= : Email of one user whose alerts are checked now, whatever their local time (for testing)}';

    protected $description = 'Check the alerts of users whose local time has reached the check hour and notify those that start to hold';

    public function handle(AlertDispatcher $dispatcher): int
    {
        $email = $this->option('user');

        if ($email === null) {
            $this->info('Sent '.$dispatcher->sendDue().' alert(s)');

            return self::SUCCESS;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with the email {$email}.");

            return self::FAILURE;
        }

        $this->info('Sent '.$dispatcher->sendForUser($user).' alert(s)');

        return self::SUCCESS;
    }
}
