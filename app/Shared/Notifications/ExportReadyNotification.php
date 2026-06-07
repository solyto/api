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
        return $this->withLocale($notifiable, fn () => [
            'title' => $this->success
                ? __('notifications.export_ready_title')
                : __('notifications.export_failed_title'),
            'body'  => $this->success
                ? __('notifications.export_ready_body')
                : __('notifications.export_failed_body'),
            'link'  => '/settings?tab=export',
        ]);
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        return $this->withLocale($notifiable, fn () =>
            (new WebPushMessage)
                ->title($this->success
                    ? __('notifications.export_ready_title')
                    : __('notifications.export_failed_title'))
                ->body($this->success
                    ? __('notifications.export_ready_body')
                    : __('notifications.export_failed_body'))
                ->icon(config('app.landing_page_url') . '/logo_cut.png')
                ->data(['url' => config('app.frontend_url') . '/settings?tab=export'])
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withLocale($notifiable, fn () => $this->success
            ? (new MailMessage)
                ->subject(__('notifications.export_ready_title'))
                ->greeting(__('notifications.export_ready_title'))
                ->line(__('notifications.export_ready_body'))
                ->action(__('notifications.action_download_export'), config('app.frontend_url') . '/settings?tab=export')
            : (new MailMessage)
                ->subject(__('notifications.export_failed_title'))
                ->greeting(__('notifications.export_failed_title'))
                ->line(__('notifications.export_failed_body'))
                ->action(__('notifications.action_open_settings'), config('app.frontend_url') . '/settings?tab=export')
        );
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return $this->withLocale($notifiable, fn () =>
            TelegramMessage::create()
                ->line($this->success
                    ? __('notifications.export_ready_title')
                    : __('notifications.export_failed_title'))
                ->line($this->success
                    ? __('notifications.export_ready_body')
                    : __('notifications.export_failed_body'))
                ->url(
                    config('app.frontend_url') . '/settings?tab=export',
                    $this->success
                        ? __('notifications.action_download_export')
                        : __('notifications.action_open_settings')
                )
        );
    }
}
