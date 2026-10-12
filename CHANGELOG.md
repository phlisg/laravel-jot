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

## 0.1.0

First release.
