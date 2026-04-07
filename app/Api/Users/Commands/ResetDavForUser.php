<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\User;
use App\Dav\Services\DavService;
use Illuminate\Console\Command;

class ResetDavForUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dav:reset-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::where('email', 'leomuck@posteo.de')->first();
        $dav = new DavService();

        foreach ($dav->calendars()->list($user) as $calendar) {
            $dav->calendars()->delete($user, $calendar->instanceId);
            $this->info('Deleted calendar ' . $calendar->instanceId);
        }

        foreach ($dav->addressBooks()->list($user) as $addressBook) {
            $dav->addressBooks()->delete($addressBook->id);
            $this->info('Deleted address book ' . $addressBook->id);
        }
    }
}
