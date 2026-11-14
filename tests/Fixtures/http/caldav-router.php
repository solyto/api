<?php

// Router for `php -S` in RemoteCalDavClientLiveTest: a loopback stand-in for a remote CalDAV server.

$size = 64 * 1024;

switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/ok':
        header('Content-Type: text/plain');
        echo 'host='.($_SERVER['HTTP_HOST'] ?? '');
        break;

    case '/large':
        header('Content-Length: '.$size);
        echo str_repeat('x', $size);
        break;

    case '/streamed':
        header('Transfer-Encoding: chunked');
        foreach (str_split(str_repeat('x', $size), 1024) as $chunk) {
            echo dechex(strlen($chunk))."\r\n".$chunk."\r\n";
            flush();
        }
        echo "0\r\n\r\n";
        break;

    default:
        http_response_code(404);
}
