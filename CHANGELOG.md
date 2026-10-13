# Changelog

All notable changes to `laravel-wide-events` are listed here, newest first. Until `1.0.0`, a minor release can contain breaking changes, see [Versioning](README.md#versioning).

## 0.3.0

Outside the `local` and `testing` environments, Jot writes only what helps debug an incident: errors, slow events, failed commands and scheduled tasks, events raised with `jot()->level()`, and 1% of 4xx responses. Normal requests are no longer written there. See [Sampling](README.md#sampling). To upgrade:

- Republish the config with `php artisan vendor:publish --tag=jot-config --force`, then reapply the app's changes. A config published before 0.3 keeps its old `sampling` and `console` keys, so `sampling.rate` stays `1.0` and every request is still written.
- To keep writing every request in an environment, set `JOT_SAMPLE_RATE=1.0`.

New:

- `jot()->level(LogLevel::WARNING, 'reason')` raises the level of an event, and sampling always keeps it. It writes `level.raised` and `level.reason`.
- `sampling.client_errors`: share of 4xx responses to keep.
- `sampling.max_per_minute`: caps the always-kept events of one kind during an outage, 10 a minute by default.
- `sampling.drop`: patterns for events to never write, with the syntax of `jot:query --where`. `Jot::keepWhen()` and `Jot::dropWhen()` take the same patterns.
- Livewire updates that only poll or refresh are dropped by default (`sampling.drop_livewire_polls`).
- `console.ignore` skips Composer hooks and dev tools: `package:discover`, `vendor:publish`, `ide-helper:*`, `boost:*`, `filament:upgrade`, `test`.
- `days`: days of files the default output keeps, 7 by default.

## 0.2.0

Events use W3C Trace Context IDs. To upgrade:

- `request_id` and `parent_id` are gone. Use `trace_id`, `span_id` and `parent_span_id`.
- IDs are hex, not UUIDs: 32 characters for `trace_id`, 16 for span IDs.
- The `X-Request-Id` response header returns the `trace_id`.
- In `config/jot.php`, rename the `request_id` key to `trace`.
- A trusted incoming `X-Request-Id` goes to `http.request.header.x-request-id`.
- Sentry tags are `trace_id` and `span_id`.

Also check:

- In a published `config/jot.php`, add `'jot:*', 'help', 'list'` to `console.ignore`. Without them, `jot:query --follow`, `help` and `list` write their own events.
- `fields.livewire` turns the Livewire update fields on or off. It defaults to on, also when a published config lacks the key.
- An empty `JOT_CHANNEL=`, `APP_VERSION=` or `JOT_REGION=` in `.env` now counts as unset. Before, an empty `JOT_CHANNEL=` sent events to the app's default log channel, such as `laravel.log`: older events may be there.
- `laravel/prompts` is required at `^0.3.16`.
- Log shippers: lines in the default Jot file have no `extra` key anymore (it repeated `trace_id`, `span_id` and `origin`), and the Monolog channel is `jot` instead of `ondemand`.

## 0.1.0

First release.
