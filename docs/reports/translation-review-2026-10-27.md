# Translation review: de, fr, es, it vs. en

Date: 2026-10-27
Scope: all keys in `resources/lang/{de,fr,es,it}/{bot,mail,notifications}.php`
(101 keys per locale), checked against `en` and the rules in `docs/I18N.md`.
No files were changed.

## Summary

What passes:

- The key check from `docs/I18N.md` prints nothing, so every locale has the same files and keys.
- Every `:name` placeholder is present in every translation.
- `%s` placeholders: same count and order everywhere (`bot.recommend_album`).
- `\n` line breaks are kept and no file has a PHP syntax error.
- Button wording is consistent within each language: infinitive in de/fr/es, imperative in it.

What needs work, in priority order:

1. Clear errors: French uses formal "vous", Spanish ¡/¿ marks are in the wrong place, and one German verb form is wrong.
2. Glossary conflicts, mostly in `de` (needs a decision from the owner).
3. Style rule breaks: Title Case, missing German hyphens, push titles that are too long.
4. Stiff or literal wording (low priority).
5. Problems in the English source that every locale has copied.
6. Keys that seem to be unused.

---

## 1. Clear errors

### 1.1 French: formal "vous" instead of "tu" (`notifications.php`)

The guide requires informal address ("tu") everywhere. These 4 strings use "vous":

| key | current | suggested |
|---|---|---|
| `friend_request_body` | Vous avez reçu une demande d'ami de :name. | Tu as reçu une demande d'ami de :name. |
| `calendar_share_body` | :sender a partagé le calendrier « :calendar » avec vous. | :sender a partagé le calendrier « :calendar » avec toi. |
| `export_ready_body` | Votre export de données est prêt à être téléchargé. | Ton export de données est prêt à être téléchargé. |
| `export_failed_body` | Votre export de données n'a pas pu être complété. Veuillez réessayer. | Ton export de données n'a pas pu être terminé. Réessaie. |

### 1.2 Spanish: ¡/¿ marks in the wrong place (`bot.php`)

| key | current | suggested |
|---|---|---|
| `welcome_unregistered` | Hey! Parece que aún no has conectado… | ¡Hola! Parece que aún no has conectado… (opening ¡ was missing and the English "Hey" was left in) |
| `token_already_registered` | ¡Tu token ya está registrado. ¡Todo ya está listo! Siéntete libre de chatear :) | Tu token ya está registrado. ¡Todo ya está listo! Siéntete libre de chatear :) |
| `add_failed` | ¿No pude añadir eso. Prueba con otro enlace o sé más específico? | No pude añadir eso. ¿Pruebas con otro enlace o me das más detalles? |
| `no_todos` | ¡No tienes tareas. Disfruta del tiempo libre! | No tienes tareas. ¡Disfruta del tiempo libre! |

### 1.3 German: wrong imperative (`bot.php`)

| key | current | suggested |
|---|---|---|
| `recommend_music` | Empfehle mir Musik | Empfiehl mir Musik |

The imperative of *empfehlen* is *empfiehl*.

---

## 2. Glossary conflicts

| locale | glossary says | file says | where |
|---|---|---|---|
| de | Todo(s) | Aufgabe(n) | `bot.added_todo`, `bot.no_todo`, `bot.empty_day`, `bot.no_todos`, `mail.delete_intro` |
| de | Account → Konto | Telegram-Account | `bot.welcome_unregistered`, `bot.token_registered` (the mail strings already say "Konto") |
| de | Dev Requests (keep as is) | Dev-Anfrage(n) | `notifications.dev_request_comment_title`, `notifications.action_view_dev_requests` |
| de | Release → Neuerscheinung / erscheinen | Neuer Screen-Release | `notifications.screen_release_title`. Suggested: "Neuer Film erscheint" or "Neuerscheinung" |
| fr | demandes de dev | demande dev | `notifications.dev_request_comment_title`, `notifications.action_view_dev_requests` |
| es | solicitudes de desarrollo | solicitudes dev | `notifications.action_view_dev_requests`. The title in the same file uses the full term. The full term makes the button 29 characters (+71%), so it conflicts with the length rule |

**Decision needed (owner):** the owner wrote `de` and uses "Aufgaben" for todos.
Either the glossary or the German files are wrong. The glossary is shared with
the frontend (`app/docs/I18N.md`), so the answer should match what the frontend
shows.

---

## 3. Style rule breaks

### 3.1 Title Case instead of sentence case

| locale | key (in both `bot.php` and `notifications.php`) | current | suggested |
|---|---|---|---|
| fr | `check_in_reminder_title` / `daily_check_in_reminder_title` | Rappel Check-in Quotidien | Rappel de check-in quotidien |
| es | `check_in_reminder_title` / `daily_check_in_reminder_title` | Recordatorio de Check-in Diario | Recordatorio diario de check-in |

### 3.2 German compound hyphens

| key | current | suggested |
|---|---|---|
| `bot.check_in_reminder_title`, `notifications.daily_check_in_reminder_title` | Tägliche Check-in Erinnerung | Tägliche Check-in-Erinnerung |
| `bot.welcome_unregistered` | …nutze den /connect Befehl dafür. | …nutze den /connect-Befehl dafür. |

### 3.3 Push titles more than ~30% longer than English

Push notification titles get cut off. Only titles where a shorter wording
exists are listed. Short buttons ("View", "Check In") go over the limit by
percentage in almost every language, but that isn't a real problem.

| key | en | over the limit | suggestion |
|---|---|---|---|
| `dev_request_comment_title` | 26 | de +38%, fr +50%, es +65% | drop "New" as `it` already does, e.g. es "Comentario en tu solicitud" |
| `music_release_title` | 17 | fr +41%, es +47% | fr "Nouvel album", es "Nuevo álbum" |
| `book_release_title` | 16 | de +31%, fr +50%, es +63% | fr "Nouveau livre", es "Nuevo libro" |
| `daily_day_reminder_title` | 14 | de +64% | "Dein Tag heute" (also less stiff than "Dein bevorstehender Tag") |
| `export_failed_title` | 13 | de +62% | no shorter natural wording; acceptable |
| `friend_request_title` | 18 | de +39%, es +44% | es "Solicitud de amistad" (as `it` already does); de compound can't be shortened much |

---

## 4. Stiff or literal wording (low priority)

Back-translations into English are included so the meaning can be checked
without speaking the language.

| locale / key | current | back-translation | issue / suggestion |
|---|---|---|---|
| de `bot.empty_day` | Es scheinen heute keine Aufgaben oder Termine anstehend zu sein! | "There seem to be no tasks or appointments being upcoming today!" | Ungrammatical. "Heute stehen anscheinend keine Todos oder Termine an!" |
| de `bot.no_todo` | Konnte keine gültige Aufgabe identifizieren, viele Entschuldigungen.. | "Could not identify a valid task, many apologies.." | Literal copy of the English joke. E.g. "Ich konnte kein gültiges Todo erkennen, sorry!" |
| de `notifications.daily_day_reminder_clear` | Du hast einen freien Tag vor dir! | "You have a day off ahead of you!" | English means "nothing scheduled", not a day off from work |
| it `notifications.daily_day_reminder_clear` | Oggi hai la giornata libera! | "Today you have the day off!" | same issue as de |
| fr `bot.day` | Voici ce que semble être ta journée : | "Here is what seems to be your day:" | Clumsy. "Voici à quoi ressemble ta journée :" |
| fr `bot.recommend_album` | Et si on écoutait %s par %s ? | "What if we listened to %s by %s?" | French uses "de" for an artist: "Et si on écoutait %s de %s ?" |
| fr `check_in_reminder_body` (bot + notifications) | As-tu complété ton check-in quotidien ? | "Have you completed your daily check-in?" | "complété" is an anglicism. "As-tu fait ton check-in du jour ?" |
| fr `notifications.screen_release_title` | Nouvelle sortie écran | "New screen release" | The English is unclear, so the French is too (see 5.5) |
| it `bot.add_failed` | Non è stato possibile aggiungerlo. Prova… | "It wasn't possible to add it. Try…" | Impersonal; the bot says "I" everywhere else. "Non sono riuscito ad aggiungerlo. Prova…" |
| de/fr `bot.choose_type` | Was soll ich damit machen? / Que dois-je en faire ? | "What should I do with it?" | Slightly different from "What should I add this as?", but it works with the option list underneath. Optional |

---

## 5. Problems in the English source

These affect every locale, so they should be fixed in `en` first. Then the
translations can follow.

1. **"Solyto" is capitalized** in all mail strings (`reset_subject`, `reset_intro`,
   `delete_subject`, `delete_intro`) and in `notifications.action_open_app`. The guide
   lists the brand as lowercase "solyto". *Decision needed: which is correct?*
2. **English titles use Title Case** ("New Friend Request", "Export Failed",
   "Daily Check-in Reminder", …). The guide asks for sentence case unless a
   language capitalizes nouns. *Decision needed: does that apply to `en` too?*
3. **`bot.no_todo`**: "many sorries.." has a double period, and every locale copied it.
4. **Capitalization in the bot strings**: "Telegram Account" in `bot.welcome_unregistered` and
   `bot.token_registered`, and "the App" in `bot.invalid_token`.
5. **`notifications.screen_release_title`**: "New Screen Release" is hard to understand.
   The notification class is `MovieReleaseNotification`, so something like
   "New movie release" would translate more cleanly.
6. **`mail.verification_subject`** says "mail address", while the body and button say
   "email address".

---

## 6. Keys that seem unused

No code references these:

- `bot.recommend_music`, `bot.whats_up_today`, `bot.recommend_album`: these exist only as
  cases in `app/Bots/Messages/SolytoMessage.php`.
- `bot.check_in_reminder_title`, `bot.check_in_reminder_body`, `bot.check_in_action`:
  not even in `SolytoMessage`. The check-in notification uses the
  `notifications.daily_check_in_reminder_*` / `notifications.action_check_in` copies.

If they really are unused, deleting them would be better than fixing their translations.

---

## Owner decisions (2026-10-27)

1. German todos: "Todo" and "Aufgabe" are both fine. Keep `de` as it is.
2. Brand spelling: "solyto" is never capitalized. All "Solyto" in the lang files (en and every locale) should be lowercase.
3. Sentence case for English titles: still open (explained to the owner, waiting for an answer).
4. Unused bot keys (section 6): keep them.

## Related bug (not a translation issue)

`app/Api/Libraries/Jobs/GrabMovieReleases.php` always passes `releaseDate: ''`
to `MovieReleaseNotification`. As a result, `notifications.screen_release_body`
reads ":title is releasing on ." in every locale. This needs a code fix.

## Applied (2026-10-27)

Owner decisions after review:

- English stays as it is, except for the brand: "Solyto" → "solyto" in every locale (en, de, fr, es, it).
- German: keep "Aufgabe(n)", "Dev-Anfrage", "Neuer Screen-Release" and the missing hyphens (3.2). Fixed:
  - `bot.recommend_music`: "Empfiehl mir Musik"
  - `bot.empty_day`: "Heute stehen anscheinend keine Aufgaben oder Termine an!"
  - `bot.no_todo`: "Ich konnte keine gültige Aufgabe erkennen, sorry!"
  - `bot.day`: "So sieht dein Tag aus:"
  - `notifications.daily_day_reminder_title`: "Dein Tag heute"
  - `notifications.daily_day_reminder_clear`: "Heute steht nichts an!"
- French: tu instead of vous (1.1), sentence case (3.1), "demande de dev", shorter release titles, the stiff wording from section 4, `screen_release_title` "Nouvelle sortie".
- Spanish: ¡/¿ fixes (1.2), sentence case (3.1), shorter titles ("Solicitud de amistad", "Nuevo álbum", "Nuevo libro", "Nuevo estreno"), `action_view_dev_requests` "Ver solicitudes", `bot.day` and `bot.error` reworded.
- Italian: `bot.add_failed` ("Non sono riuscito…"), `daily_day_reminder_clear` "Oggi non hai impegni!".
- Unused bot keys: kept.

Still open:

- `app/Shared/Notifications/TestNotification.php` hardcodes "Open Solyto" twice (not translated, capital S). Code change, out of translator scope.
- Movie release date bug (see above).
