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
    case ADDED_LINK = 'Got it. Link added to your library.';
    case ADDED_MUSIC = 'Got it. Album added to your music library.';
    case ADDED_BOOK = 'Got it. Book added to your library.';
    case ADDED_MOVIE = 'Got it. Movie added to your library.';
    case ADDED_GAME = 'Got it. Game added to your library.';
    case ADDED_RECIPE = 'Got it. Recipe saved.';
    case ADDED_PLANT = 'Got it. Plant added to your library.';
    case ADDED_QUOTE = 'Got it. Quote saved.';
    case ADDED_TODO = 'Got it. Added to your todos.';
    case ADDED_NOTE = 'Got it. Saved as a note.';
    case ADDED_FEED = 'Got it. Feed subscription added.';
    case ADDED_CLIPBOARD = 'Got it. Added to your clipboard.';
    case CHOOSE_TYPE = "What should I add this as?\n\nPick one of the options below.";
    case ADD_FAILED = 'I couldn\'t add that. Try a different link or be more specific?';
    case NO_LINK = 'Sorry, I could not identify a link.';
    case NO_TODO = 'Could not identify a valid Todo, many sorries..';
    case EMPTY_DAY = 'There seem to be no todos or appointments up today!';
    case ERROR = 'Sorry, something unexpected happened. I\'m trying to fix it.';
}
