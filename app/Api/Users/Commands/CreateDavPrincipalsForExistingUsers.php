<?php

namespace App\Api\Users\Commands;

use App\Api\Users\Models\User;
use App\Dav\Models\Principal;
use App\Dav\Services\DavService;
use Illuminate\Console\Command;

class CreateDavPrincipalsForExistingUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dav:create-principals';

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
        $users = User::all();
        $dav = app(DavService::class);

        foreach ($users as $user) {
            $principal = Principal::where('email', $user->email)->first();

            if ($principal) {
                continue;
            }

            $dav->principals()->create($user);
        }
    }
}
