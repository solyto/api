<?php

return [
    'relying_party_name' => env('APP_NAME', 'Solyto'),
    'relying_party_id'   => env('WEBAUTHN_RP_ID', 'localhost'),
    'origin'             => env('WEBAUTHN_ORIGIN', 'http://localhost:5173'),
];
