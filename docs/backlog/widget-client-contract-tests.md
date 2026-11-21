# Backlog: contract tests for the native Android widgets

created: 2026-11-21

## What

`tests/Unit/ClientContractTest.php` pins what released builds of the web app
(`solyto/app`) read. The native Android widgets (`solyto/mobile`
`android/app/src/main/java/app/solyto/widget/WidgetApi.java`, `WidgetKind.java`)
are a second client: they call `/api/v1/widgets/*` themselves and ship with
the Android app, on their own release cycle. `WidgetApiTest` covers those
routes as module tests, but nothing pins what an installed widget build reads,
so a refactor could change a widget response and keep every test green.

## Suggestion

Same method as the web app's contract tests: for each released Android build,
find the fields its widget code reads, and add one contract test per thing
read, with the build and the Java file and line in the header. Add the widget
routes to a route fixture like `tests/Fixtures/client-contract/1.1-routes.json`.
