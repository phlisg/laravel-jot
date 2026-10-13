# Changelog

All notable changes to `laravel-wide-events` are listed here, newest first. Until `1.0.0`, a minor release can contain breaking changes, see [Versioning](README.md#versioning).

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
