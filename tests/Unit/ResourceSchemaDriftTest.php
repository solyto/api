<?php

/**
 * Rule 2.6 check: every API resource's `@OA\Schema` documents the same
 * top-level keys that its `toArray()` returns.
 *
 * Both sides are read statically from the source file:
 * - schema: the `@OA\Property(property="…")` entries directly inside the
 *   class docblock's `@OA\Schema(…)` (nested properties are ignored);
 * - resource: the top-level string keys of the array literal(s) returned by
 *   `toArray()` (keys inside nested arrays, closures or `merge()` calls are
 *   ignored).
 *
 * Drift that already existed when this test was added is listed in
 * RESOURCE_SCHEMA_KNOWN_DRIFT so that only NEW drift fails. Remove an entry
 * once the resource and its annotation agree again.
 *
 * Entry formats:
 * - `<Resource>: <key> not in schema`   — `toArray()` emits it, schema lacks it
 * - `<Resource>: <key> not in toArray`  — schema documents it, `toArray()` lacks it
 * - `<Resource>: no @OA\Schema`
 * - `<Resource>: no toArray() array literal`
 */
const RESOURCE_SCHEMA_KNOWN_DRIFT = [
    // Not a JsonResource: an empty class that only carries the schema.
    'StatisticsResource: no toArray() array literal',
];

it('keeps resource @OA\Schema properties in sync with toArray() keys', function () {
    $schemaProperties = function (string $docblock): ?array {
        $start = strpos($docblock, '@OA\Schema(');
        if ($start === false) {
            return null;
        }

        // Walk the Schema(...) body and collect property names of the
        // @OA\Property( annotations that sit directly at depth 1.
        $properties = [];
        $depth = 0;
        $length = strlen($docblock);
        $inString = false;

        for ($i = $start + strlen('@OA\Schema'); $i < $length; $i++) {
            $char = $docblock[$i];

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }
            if ($inString) {
                continue;
            }

            if ($char === '(' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && substr($docblock, $i, 13) === '@OA\Property(') {
                if (preg_match('/\G@OA\\\\Property\(\s*(?:\*\s*)*property="([^"]+)"/', $docblock, $match, 0, $i)) {
                    $properties[] = $match[1];
                }
            }
        }

        return $properties;
    };

    $toArrayKeys = function (array $tokens): ?array {
        $count = count($tokens);
        $keys = [];
        $found = false;

        // Locate `function toArray`.
        for ($i = 0; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === 'toArray') {
                    break;
                }
            }
        }
        if ($i === $count) {
            return null;
        }

        // Find the body's opening brace, then walk the body.
        while ($i < $count && $tokens[$i] !== '{') {
            $i++;
        }
        $bodyDepth = 0;
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $bodyDepth++;
            } elseif ($token === '}') {
                $bodyDepth--;
                if ($bodyDepth === 0) {
                    break;
                }
            }

            // Only `return [` statements of toArray itself (not of closures).
            if ($bodyDepth !== 1 || ! is_array($token) || $token[0] !== T_RETURN) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($tokens[$j] !== '[') {
                continue;
            }

            $found = true;
            $depth = 0;
            for (; $j < $count; $j++) {
                $t = $tokens[$j];
                if (in_array($t, ['[', '(', '{'], true)) {
                    $depth++;
                } elseif (in_array($t, [']', ')', '}'], true)) {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $k = $j + 1;
                    while ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        $k++;
                    }
                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_DOUBLE_ARROW) {
                        $keys[] = trim($t[1], '\'"');
                    }
                }
            }
        }

        return $found ? array_values(array_unique($keys)) : null;
    };

    $files = array_merge(
        glob(base_path('app/Api/*/Resources/*Resource.php')),
        glob(base_path('app/Plugins/Resources/*Resource.php')),
        glob(base_path('app/Plugins/*/Resources/*Resource.php')),
    );
    expect($files)->not->toBeEmpty();

    $drift = [];

    foreach ($files as $file) {
        $name = basename($file, '.php');
        $tokens = token_get_all(file_get_contents($file));

        $schema = null;
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_DOC_COMMENT && str_contains($token[1], '@OA\Schema(')) {
                $schema = $schemaProperties($token[1]);
                break;
            }
        }

        $keys = $toArrayKeys($tokens);

        if ($schema === null) {
            $drift[] = "{$name}: no @OA\\Schema";
        }
        if ($keys === null) {
            $drift[] = "{$name}: no toArray() array literal";
        }
        if ($schema === null || $keys === null) {
            continue;
        }

        foreach (array_diff($keys, $schema) as $key) {
            $drift[] = "{$name}: {$key} not in schema";
        }
        foreach (array_diff($schema, $keys) as $key) {
            $drift[] = "{$name}: {$key} not in toArray";
        }
    }

    sort($drift);
    $known = RESOURCE_SCHEMA_KNOWN_DRIFT;
    sort($known);

    expect($drift)->toBe($known, 'Resource schema drift changed. New: '.implode(', ', array_diff($drift, $known)).'; fixed: '.implode(', ', array_diff($known, $drift)));
});
