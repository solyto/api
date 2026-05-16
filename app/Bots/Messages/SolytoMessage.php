<?php

namespace App\Bots\Messages;

enum SolytoMessage: string
{
    case WELCOME                  = 'bot.welcome';
    case WELCOME_UNREGISTERED     = 'bot.welcome_unregistered';
    case RECOMMEND_MUSIC          = 'bot.recommend_music';
    case WHATS_UP_TODAY           = 'bot.whats_up_today';
    case INVALID_TOKEN            = 'bot.invalid_token';
    case TOKEN_REGISTERED         = 'bot.token_registered';
    case TOKEN_ALREADY_REGISTERED = 'bot.token_already_registered';
    case RECOMMEND_ALBUM          = 'bot.recommend_album';
    case DAY                      = 'bot.day';
    case ADDED_LINK               = 'bot.added_link';
    case ADDED_MUSIC              = 'bot.added_music';
    case ADDED_BOOK               = 'bot.added_book';
    case ADDED_MOVIE              = 'bot.added_movie';
    case ADDED_GAME               = 'bot.added_game';
    case ADDED_RECIPE             = 'bot.added_recipe';
    case ADDED_PLANT              = 'bot.added_plant';
    case ADDED_QUOTE              = 'bot.added_quote';
    case ADDED_TODO               = 'bot.added_todo';
    case ADDED_NOTE               = 'bot.added_note';
    case ADDED_FEED               = 'bot.added_feed';
    case ADDED_CLIPBOARD          = 'bot.added_clipboard';
    case CHOOSE_TYPE              = 'bot.choose_type';
    case ADD_FAILED               = 'bot.add_failed';
    case NO_LINK                  = 'bot.no_link';
    case NO_TODO                  = 'bot.no_todo';
    case EMPTY_DAY                = 'bot.empty_day';
    case NO_TODOS                 = 'bot.no_todos';
    case ERROR                    = 'bot.error';
    case CANCELLED                = 'bot.cancelled';

    public function trans(): string
    {
        return __($this->value);
    }
}
