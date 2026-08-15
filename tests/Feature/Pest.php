<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Feature Test Case
|--------------------------------------------------------------------------
|
| Every feature test in the `tests/Feature` directory is bound to the
| base `TestCase`. Individual test files opt into `RefreshDatabase` (or
| `DatabaseTransactions`) as needed so each test controls its own
| database lifecycle.
|
*/

uses(TestCase::class)->in(__DIR__);
