<?php

namespace App\Bots\Commands;

use App\Bots\Traits\HasBotSelector;
use App\Shared\Services\TelegramBotService;
use Illuminate\Console\Command;

class TelegramBotStatus extends Command
{
    use HasBotSelector;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bots:status:telegram';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the status of a Telegram Bot.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $bot = $this->selectBot();
        $service = new TelegramBotService($bot['telegram_token']);
        $info = $service->getStatus($bot);

        if ($info === null) {
            $this->error('Could not data from Telegram.');
        }

        $this->info(json_encode($info, JSON_PRETTY_PRINT));
    }
}
