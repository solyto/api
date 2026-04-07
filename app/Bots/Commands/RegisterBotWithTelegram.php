<?php

namespace App\Bots\Commands;

use App\Bots\Traits\HasBotSelector;
use App\Shared\Services\TelegramBotService;
use Illuminate\Console\Command;

class RegisterBotWithTelegram extends Command
{
    use HasBotSelector;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bots:register:telegram';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register a bot with Telegram Webhooks.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $bot = $this->selectBot();
        $service = new TelegramBotService($bot['telegram_token']);
        $service->setWebhook($bot);
        $service->setCommands($bot);
    }
}
