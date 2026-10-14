<?php

use App\Api\DevRequests\Notifications\DevRequestCreatedNotification;
use App\Shared\Helpers\DockerSecretHelper;
use App\Shared\Notifications\AdminNotification;
use App\Shared\Notifications\Channels\NtfyChannel;
use App\Shared\Notifications\Channels\NtfyMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

covers(NtfyChannel::class, NtfyMessage::class, AdminNotification::class);

function enableNtfy(?string $token = null): void
{
    config([
        'services.ntfy.url'   => 'https://ntfy.example.test',
        'services.ntfy.topic' => 'solyto-admin',
        'services.ntfy.token' => $token,
    ]);
}

function sendNtfy(string $title = 'Dark mode'): void
{
    Notification::route(NtfyChannel::class, config('services.ntfy.topic'))
        ->notify(new DevRequestCreatedNotification($title));
}

describe('AdminNotification::via', function () {
    it('returns no channel when ntfy is not configured', function () {
        config(['services.ntfy.url' => null, 'services.ntfy.topic' => null]);
        Http::fake();

        expect((new DevRequestCreatedNotification('x'))->via(new AnonymousNotifiable()))->toBe([]);

        sendNtfy();

        Http::assertNothingSent();
    });

    it('returns no channel when url or topic is an empty string', function (string $key) {
        enableNtfy();
        config(["services.ntfy.$key" => '']);
        Http::fake();

        expect((new DevRequestCreatedNotification('x'))->via(new AnonymousNotifiable()))->toBe([]);

        sendNtfy();

        Http::assertNothingSent();
    })->with(['url', 'topic']);

    it('stays off when the topic secret file is empty', function () {
        $previous = ['APP_DEBUG' => getenv('APP_DEBUG'), 'NTFY_TOPIC_FILE' => getenv('NTFY_TOPIC_FILE')];
        $file = tempnam(sys_get_temp_dir(), 'ntfy');
        putenv('APP_DEBUG=false');
        putenv("NTFY_TOPIC_FILE=$file");

        try {
            enableNtfy();
            config(['services.ntfy.topic' => DockerSecretHelper::get('NTFY_TOPIC')]);
            Http::fake();

            expect(config('services.ntfy.topic'))->toBe('');

            sendNtfy();

            Http::assertNothingSent();
        } finally {
            unlink($file);
            foreach ($previous as $key => $value) {
                $value === false ? putenv($key) : putenv("$key=$value");
            }
        }
    });

    it('returns the ntfy channel when url and topic are set', function () {
        enableNtfy();

        expect((new DevRequestCreatedNotification('x'))->via(new AnonymousNotifiable()))->toBe([NtfyChannel::class]);
    });
});

describe('NtfyChannel::send', function () {
    it('posts topic, title and message as JSON to the server root', function () {
        enableNtfy();
        Http::fake();

        sendNtfy('Dark mode');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) =>
            $request->method() === 'POST'
            && $request->url() === 'https://ntfy.example.test'
            && $request->isJson()
            && $request->data() === [
                'topic'   => 'solyto-admin',
                'title'   => 'New dev request',
                'message' => 'New dev request: Dark mode',
            ]
        );
    });

    it('sends a bearer token when one is configured', function () {
        enableNtfy('secret-token');
        Http::fake();

        sendNtfy();

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer secret-token']);
    });

    it('sends no Authorization header without a token', function (?string $token) {
        enableNtfy($token);
        Http::fake();

        sendNtfy();

        Http::assertSent(fn (Request $request) => !$request->hasHeader('Authorization'));
    })->with([null, '']);

    it('logs status and host but not the token on an error response', function () {
        enableNtfy('secret-token');
        Http::fake(['*' => Http::response('boom', 500)]);
        Log::spy();

        sendNtfy();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) =>
            $context === ['status' => 500, 'host' => 'ntfy.example.test']
            && !str_contains($message . json_encode($context), 'secret-token')
        );
    });

    it('logs the host but not the token on a connection error', function () {
        enableNtfy('secret-token');
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        Log::spy();

        sendNtfy();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) =>
            $context === ['host' => 'ntfy.example.test']
            && !str_contains($message . json_encode($context), 'secret-token')
        );
    });
});
