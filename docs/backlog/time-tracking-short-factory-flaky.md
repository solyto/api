# Backlog: flaky "creates a short entry" test

created: 2026-11-14

## What

`TimeTrackingEntryFactory::short()`
(`app/Api/TimeTracking/Factories/TimeTrackingEntryFactory.php:83-88`) sets
`duration_minutes` to `numberBetween(15, 60)`. Faker's bounds are inclusive,
so it can return 60. The test "creates a short entry"
(`app/Api/TimeTracking/Tests/TimeTrackingTest.php:41-46`) asserts
`toBeLessThan(60)`, so it fails about 1 run in 46.

## Why

It's a random red `composer test` on `main` that has nothing to do with the
change being tested. It came up during the OPML import job (2026-11-13).

## Steps

1. Change the factory to `numberBetween(15, 59)`. "Short" means under an
   hour, which is what the test says, and `long()` (240–480) is already
   clearly above 60.
2. Nothing else calls `short()` (checked 2026-11-14), so no other test
   changes.

## Notes

- Check: run the test with fixed seeds, or temporarily stub the faker to
  return 60, and confirm it passes.
- The alternative, relaxing the test to `toBeLessThanOrEqual(60)`, would
  make a 60-minute entry count as "short". Not recommended.
