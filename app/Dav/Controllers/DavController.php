<?php

namespace App\Dav\Controllers;

use App\Dav\Factories\DavServerFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Sabre\HTTP\Response as SabreResponse;
use Sabre\HTTP\Sapi;

class DavController
{
    public function handle(Request $request, DavServerFactory $factory)
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

