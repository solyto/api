<?php

namespace App\Bots\Events;

enum SolytoBotEvent: string
{
    case WELCOME = 'welcome';
    case WELCOME_UNREGISTERED = 'welcome_unregistered';
    case REGISTER_STARTED = 'register_started';
    CASE REGISTER_SUCCESS = 'register_success';
    case QUICK_ADD_AWAITING_TYPE = 'quick_add_awaiting_type';
}
