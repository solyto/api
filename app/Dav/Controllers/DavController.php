<?php

namespace App\Dav\Controllers;

use App\Dav\Factories\DavServerFactory;
use App\Shared\Pulse\PulseMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Sabre\HTTP\Response as SabreResponse;
use Sabre\HTTP\Sapi;

class DavController
{
    public function handle(Request $request, DavServerFactory $factory): Response
    {
        // Measured per method and client for the Pulse DAV card: Slow Requests only ever shows the one dav/{path} route.
        $startedAt = PulseMetrics::startTimer();

        try {
            return $this->serve($factory);
        } finally {
            PulseMetrics::davRequest($request->method(), $request->userAgent(), PulseMetrics::elapsedMs($startedAt));
        }
    }

    private function serve(DavServerFactory $factory): Response
    {
        $server = $factory->createServer();
        $server->setLogger(Log::channel('dav'));

        $response             = new SabreResponse();
        $server->httpRequest  = Sapi::getRequest();
        $server->httpResponse = $response;
        $server->exec();

        return response(
            $response->getBody(),
            $response->getStatus(),
            $response->getHeaders()
        );
    }
}

