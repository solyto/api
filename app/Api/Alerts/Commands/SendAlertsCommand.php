<?php

namespace App\Api\Alerts\Commands;

use App\Api\Alerts\Services\AlertDispatcher;
use Illuminate\Console\Command;

class SendAlertsCommand extends Command
{
    protected $signature = 'app:send-alerts';

    protected $description = 'Check the alerts of users whose local time has reached the check hour and notify those that start to hold';

    public function handle(AlertDispatcher $dispatcher): void
    {
        $this->info('Sent '.$dispatcher->sendDue().' alert(s)');
    }
}
