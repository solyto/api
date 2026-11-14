<?php

// Router for `php -S` in FeedReaderLiveTest: a loopback stand-in for a remote feed server.

function sendChunk(string $data): void
{
    if ($data !== '') {
        echo dechex(strlen($data))."\r\n".$data."\r\n";
        flush();
    }
}

switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/feed':
        header('Content-Type: application/rss+xml');
        header('Content-Encoding: gzip');
        echo gzencode(file_get_contents(__DIR__.'/../feed.rss'));
        break;

    case '/feed-negotiated':
        // Compressed only when the client asks for it, like most servers.
        $gzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip');
        header('Content-Type: application/rss+xml');
        header('X-Sent-Encoding: '.($gzip ? 'gzip' : 'identity'));
        if ($gzip) {
            header('Content-Encoding: gzip');
        }
        echo $gzip ? gzencode(file_get_contents(__DIR__.'/../feed.rss')) : file_get_contents(__DIR__.'/../feed.rss');
        break;

    case '/feed-mislabelled-gzip':
        // A broken server: the body is plain whatever was asked for, but labelled as gzip.
        header('Content-Type: application/rss+xml');
        header('Content-Encoding: gzip');
        header('X-Accept-Encoding: '.($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
        echo file_get_contents(__DIR__.'/../feed.rss');
        break;

    case '/gzip-bomb':
        // Zeros compress about 1000:1, so the bytes on the wire stay far below the 5 MB cap while the
        // body inflates to gigabytes. Streamed until the client hangs up, which ends this script.
        header('Content-Type: application/rss+xml');
        header('Content-Encoding: gzip');
        header('Transfer-Encoding: chunked');
        $deflate = deflate_init(ZLIB_ENCODING_GZIP);
        $megabyte = str_repeat("\0", 1024 * 1024);
        for ($i = 0; $i < 4096; $i++) {
            sendChunk(deflate_add($deflate, $megabyte, ZLIB_SYNC_FLUSH));
        }
        sendChunk(deflate_add($deflate, '', ZLIB_FINISH));
        echo "0\r\n\r\n";
        break;

    case '/redirect-relative':
        header('Location: /feed', true, 302);
        break;

    case '/redirect-loopback':
        header('Location: http://127.0.0.1:'.$_SERVER['SERVER_PORT'].'/feed', true, 302);
        break;

    default:
        http_response_code(404);
}
