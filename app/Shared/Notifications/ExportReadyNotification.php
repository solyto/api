<?php

namespace App\Shared\Notifications;

use App\Shared\Notifications\Channels\TelegramMessage;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\WebPush\WebPushMessage;

class ExportReadyNotification extends BaseNotification
{
    public function __construct(
        private readonly string $exportId,
        private readonly bool $success = true,
    ) {}

    protected function getNotificationType(): string
    {
        return 'export_ready';
    }

    public function databaseType(object $notifiable): string
    {
        return 'export_ready';
    }

    public function toDatabase(object $notifiable): array
    {
        if ($this->success) {
            return [
                'message' => 'Your data export is ready to download.',
                'export_id' => $this->exportId,
            ];
        }

        return [
            'message' => 'Your data export failed. Please try again later.',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->success) {
            return (new MailMessage)
                ->subject('Your Export is Ready')
                ->greeting('Export Complete')
                ->line('Your data export is ready to download. The file will be available for 48 hours.')
                ->action('Download Export', config('app.frontend_url').'/settings?tab=export')
                ->line('If you did not request this export, please ignore this email.');
        }

        return (new MailMessage)
            ->subject('Export Failed')
            ->greeting('Export Failed')
            ->line('Your data export could not be completed. Please try again later.')
            ->action('Open Settings', config('app.frontend_url').'/settings?tab=export');
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        if ($this->success) {
            return (new WebPushMessage)
                ->title('Export Ready')
                ->body('Your data export is ready to download.')
                ->icon(config('app.landing_page_url').'/logo_cut.png')
                ->action('Download', 'open')
                ->data([
                    'url' => config('app.frontend_url').'/settings?tab=export',
                ]);
        }

        return (new WebPushMessage)
            ->title('Export Failed')
            ->body('Your data export could not be completed.')
            ->icon(config('app.landing_page_url').'/logo_cut.png')
            ->data([
                'url' => config('app.frontend_url').'/settings?tab=export',
            ]);
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $msg = TelegramMessage::create();

        if ($this->success) {
            $msg->line('Your data export is ready to download.')
                ->url(config('app.frontend_url').'/settings?tab=export', 'Download Export');
        } else {
            $msg->line('Your data export failed. Please try again later.')
                ->url(config('app.frontend_url').'/settings?tab=export', 'Open Settings');
        }

        return $msg;
    }
}
