<?php

/**
 * Rules 13.3 and 13.4 check: a migration added after the oldest supported
 * frontend build (1.0.15) must not change existing data or schema in a way
 * an old client can notice, unless it is allowlisted with a reason.
 *
 * Baseline: `tests/Fixtures/client-contract/1.0.15-migrations.txt` holds the
 * migration filenames at tag `1.0.15`. Regenerate it at the new oldest
 * supported tag with
 * `git ls-tree --name-only <tag> database/migrations/ > tests/Fixtures/client-contract/<tag>-migrations.txt`
 * and drop allowlist entries that are now inside the baseline. Files are
 * compared by basename.
 *
 * Every migration in `database/migrations/` that is not in the baseline is
 * read statically. Only the body of `up()` is scanned (comments ignored):
 * `down()` legitimately drops what `up()` added, and only `up()` runs on
 * deploy. Flagged patterns:
 * - `dropColumn` / `dropColumns`
 * - `renameColumn`
 * - `->change()`
 * - `Schema::drop(` / `Schema::dropIfExists(` (also via `Schema::connection(...)`)
 * - `Schema::rename(` (also via `Schema::connection(...)`)
 * - `DB::statement(` (also via `DB::connection(...)`)
 * - `DB::table(...)->update(` / `->delete(` in the same statement, across lines
 * - model calls: any class imported with `use App\...` (or written as
 *   `\App\...`) that is called statically (`Class::`) inside `up()`. This
 *   over-flags on purpose (it catches `User::query()` as well as
 *   `NoteCategory::provisionJournal()`), so every app-code call in a
 *   migration becomes a deliberate decision.
 *
 * This is a heuristic: it catches these patterns, not every possible data
 * change. Rule 13.3 still applies at review time.
 *
 * Allowlist format: `filename => reason`. The reason names the backlog entry
 * or decision that makes the migration safe for old clients. An entry whose
 * file no longer exists fails the test.
 */
const MIGRATION_COMPATIBILITY_ALLOWLIST = [
    '2026_10_10_000001_backfill_journal_categories.php' => 'hidden from old clients by `hidden-note-categories-opt-in`',
];

const MIGRATION_COMPATIBILITY_BASELINE = 'tests/Fixtures/client-contract/1.0.15-migrations.txt';

/**
 * Returns the source of the `up()` body without comments, or null if the
 * file has no `up()` method.
 */
function migrationUpBody(string $source): ?string
{
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === 'up') {
            break;
        }
    }
    if ($i === $count) {
        return null;
    }

    while ($i < $count && $tokens[$i] !== '{') {
        $i++;
    }

    $body = '';
    $depth = 0;
    for (; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($token === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $body .= is_array($token) ? $token[1] : $token;
    }

    return $body;
}

/**
 * Returns the short names (or aliases) of classes imported from `App\`.
 *
 * @return list<string>
 */
function migrationAppImports(string $source): array
{
    preg_match_all('/^use\s+App\\\\([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $matches, PREG_SET_ORDER);

    return array_map(function (array $match): string {
        if (isset($match[2]) && $match[2] !== '') {
            return $match[2];
        }
        $parts = explode('\\', $match[1]);

        return end($parts);
    }, $matches);
}

/**
 * @return list<string> the flagged patterns found in the migration
 */
function migrationCompatibilityFindings(string $source): array
{
    $body = migrationUpBody($source);
    if ($body === null) {
        return [];
    }

    $schema = 'Schema::(?:connection\([^)]*\)\s*->\s*)?';
    $db = 'DB::(?:connection\([^)]*\)\s*->\s*)?';

    $patterns = [
        'dropColumn' => '/\bdropColumns?\s*\(/',
        'renameColumn' => '/\brenameColumn\s*\(/',
        '->change()' => '/->\s*change\s*\(\s*\)/',
        'Schema::drop' => '/'.$schema.'drop(?:IfExists)?\s*\(/',
        'Schema::rename' => '/'.$schema.'rename\s*\(/',
        'DB::statement' => '/'.$db.'statement\s*\(/',
        'DB::table()->update/delete' => '/'.$db.'table\s*\([^;]*?->\s*(?:update|delete)\s*\(/s',
    ];

    $findings = [];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $body)) {
            $findings[] = $label;
        }
    }

    foreach (migrationAppImports($source) as $class) {
        if (preg_match('/(?<![\w\\\\$])'.preg_quote($class, '/').'\s*::/', $body)) {
            $findings[] = "model call {$class}::";
        }
    }
    if (preg_match_all('/\\\\App\\\\(?:\w+\\\\)*(\w+)\s*::/', $body, $matches)) {
        foreach ($matches[1] as $class) {
            $findings[] = "model call {$class}::";
        }
    }

    return array_values(array_unique($findings));
}

it('reads the 1.0.15 migration baseline', function () {
    $baseline = file(base_path(MIGRATION_COMPATIBILITY_BASELINE), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($baseline)->toHaveCount(144);

    $missing = array_filter($baseline, fn (string $path) => ! file_exists(base_path('database/migrations/'.basename($path))));
    expect($missing)->toBe([], 'Baseline lists migrations that do not exist: '.implode(', ', $missing));
});

it('flags migrations after 1.0.15 that change existing data or schema', function () {
    $baseline = array_map(
        'basename',
        file(base_path(MIGRATION_COMPATIBILITY_BASELINE), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );

    $files = glob(base_path('database/migrations/*.php'));
    expect($files)->not->toBeEmpty();

    $flagged = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $baseline, true)) {
            continue;
        }

        $findings = migrationCompatibilityFindings(file_get_contents($file));
        if ($findings !== [] && ! array_key_exists($name, MIGRATION_COMPATIBILITY_ALLOWLIST)) {
            $flagged[] = $name.' ('.implode(', ', $findings).')';
        }
    }

    expect($flagged)->toBe([], 'Migrations after 1.0.15 change existing data or schema (rules 13.3, 13.4). Make them safe for old clients or add them to MIGRATION_COMPATIBILITY_ALLOWLIST with a reason: '.implode('; ', $flagged));
});

it('keeps the migration compatibility allowlist clean', function () {
    $stale = array_values(array_filter(
        array_keys(MIGRATION_COMPATIBILITY_ALLOWLIST),
        fn (string $name) => ! file_exists(base_path('database/migrations/'.$name)),
    ));

    expect($stale)->toBe([], 'Allowlisted migrations that no longer exist: '.implode(', ', $stale));

    $empty = array_keys(array_filter(MIGRATION_COMPATIBILITY_ALLOWLIST, fn (string $reason) => trim($reason) === ''));
    expect($empty)->toBe([], 'Allowlisted migrations without a reason: '.implode(', ', $empty));
});
