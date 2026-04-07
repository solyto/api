<?php

namespace App\Bots\Messages;

enum SolytoMessage: string
{
    case WELCOME = 'Hey there! What can I do for you today?';
    case WELCOME_UNREGISTERED = 'Hey! It seems you have not yet connected your Telegram Account. Please use the /connect command to do so.';
    case RECOMMEND_MUSIC = 'Recommend some music';
    case WHATS_UP_TODAY = 'What\'s up today?';
    case INVALID_TOKEN = 'Sorry, I could not correctly identify your token. Check with the App again?';
    case TOKEN_REGISTERED = 'Your token was accepted. The app is now connected to your Telegram Account.';
    case TOKEN_ALREADY_REGISTERED = 'Your token was already registered. Everything is already great! Feel free to have a chat :)';
    case RECOMMEND_ALBUM = 'How about %s by %s?';
    case DAY = "This seems to be your day:\n\n";
    case LINK = 'Great, I have added this link to your library :)';
    case NO_LINK = 'Sorry, I could not identify a link.';
    case TODO = 'Alrighty, added to your Todos!';
    case NO_TODO = 'Could not identify a valid Todo, many sorries..';
    case EMPTY_DAY = 'There seem to be no todos or appointments up today!';
    case ERROR = 'Sorry, something unexpected happened. I\'m trying to fix it.';
}
