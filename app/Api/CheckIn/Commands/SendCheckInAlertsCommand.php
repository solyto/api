<?php

namespace App\Api\CheckIn\Commands;

use App\Api\CheckIn\Services\CheckInAlertService;
use Illuminate\Console\Command;

class SendCheckInAlertsCommand extends Command
{
    protected $signature = 'app:send-check-in-alerts';

    protected $description = 'Send the check-in reminders whose time has come and whose tracker is not done yet';

    public function handle(CheckInAlertService $alerts): void
    {
        $sent = $alerts->sendDue();

        $this->info("Sent {$sent} check-in reminder(s)");
    }
}
