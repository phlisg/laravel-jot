# Querying events

`php artisan jot:query` reads wide events back from the log files of the server it runs on. The README covers the commands used most, see [Reading events](../README.md#reading-events). This page lists every option.

- `jot:query`: the most recent events, with filters.
- `jot:query <trace_id>`: one trace.
- `jot:query <span_id>`: one event, with every field.

At a terminal, it opens an interactive list. Piped, in CI (continuous integration), with `-n` (`--no-interaction`) or with `--json`, it prints plain lines.

It reads the outputs that write files: the default Jot file and any `single` or `daily` channel. For other outputs, such as `stderr` or Sentry Logs, it prints where else to look.

For a live stream of all log messages, Jot events included, run `php artisan pail`.

## Recent events

Without an ID, `jot:query` lists the most recent events, oldest first, one line per event: time, trace ID, then a summary of the event.

```sh
php artisan jot:query                                   # last 20 events from today's files
php artisan jot:query --limit=50 --since=15m
php artisan jot:query --errors                          # error.type set, 5xx status or failed job
php artisan jot:query --slow                            # slower than jot.sampling.slow_ms
php artisan jot:query --where=http.route=events.* --where=user.id=42
php artisan jot:query --where=entrypoint=queue,schedule  # jobs and scheduled tasks
php artisan jot:query --follow                          # keep printing new events, Ctrl+C to stop
php artisan jot:query --json                            # one JSON line per event
```

```
06:47:00  555ee94c04b56b2e7b91a58635e1a1f8  http  d7a93d989a9b145b  GET events.show 200  5ms
06:47:00  7b096114f4407571aa14af9f01e5ef9d  http  13cbb92ae552c95a  POST events.details 200  0ms
06:47:00  f682e78131053797dc9bb24718dd5ee7  http  f753e791229612e7  POST events.pay 200  1ms
06:47:00  e3a6df5d662b366fad87a7969a4ff985  http  c6b2d9316c7f6888  POST stripe.webhook 204  1ms
Run jot:query <trace_id> for a trace, or <span_id> for every field.
```

- `--limit`: how many events to list, `20` by default.
- `--where`: `key=value` on the flat field names, with `*` wildcards. Commas list alternatives: `--where=entrypoint=http,queue`. Repeat `--where` to require several fields.
- `--errors`: events with an `error.type`, a 5xx status or a failed job.
- `--slow`: events slower than `sampling.slow_ms` (1000 ms).
- `--since`: a number and a unit (`s`, `m`, `h` or `d`), such as `15m`.
- `--days=1`: how many days of files to read. `--since` reads further back when it needs to.
- `--follow`: prints new events as they are written, as plain lines, even at a terminal.

These options work without an ID only. With an ID, `jot:query` refuses all of them except `--days` and `--json`.

When nothing matches, `jot:query` names the files it read. See [Troubleshooting](../README.md#troubleshooting) for the usual causes.

## Browsing

At a terminal, the recent events open in a table. Arrow keys move, `/` searches (trace and span IDs match too), `r` reloads the events, and Enter prints every field of the selected event:

```sh
php artisan jot:query --since=1h
```

![Browsing recent events with jot:query: search, every field of a request, then its trace and the failed job](images/browse.gif)

```
┌ Recent events ──────────────────────────────────────────────────────────┐
│ / Search                                                                │
├──────────┬───────┬───────────────────────┬────┬─────────────────────────┤
│ Time     │ Type  │ What                  │ ms │ Error                   │
├──────────┼───────┼───────────────────────┼────┼─────────────────────────┤
│ 06:51:12 │ queue │ ChargeCard failed     │ 7  │ RuntimeException: Card… │
│ 06:51:12 │ http  │ POST jobs.failing 200 │ 13 │                         │
└──────────┴───────┴───────────────────────┴────┴─────────────────────────┘
```

- The newest event is at the top, where the cursor starts. The plain list prints oldest first, like `tail`, so the newest line sits next to the prompt.
- After the fields, pick Back to the list, Show the trace or Quit. Show the trace lists the events of the trace as a tree, see [One trace](#one-trace). Enter on any of them prints its fields.
- The table takes the same filters as the plain list.

## One trace

With a trace ID, `jot:query` prints the events of the trace as a tree. `parent_span_id` places each event under the one that started it:

```sh
php artisan jot:query 4bf92f3577b34da6a3ce929d0e0e4736
```

```
Trace 4bf92f3577b34da6a3ce929d0e0e4736, 2 event(s)
http  f56220033574661e  POST checkout.store 200  412ms
└─ queue  69f6835287391bf7  ChargeCard failed  10ms  RuntimeException: Card network timeout
```

Arguments:

- Trace ID (32 hex characters): prints the whole trace. The `X-Request-Id` response header holds it.
- Span ID (16 hex characters): prints the same tree, then every field of that event.

Options:

- `--days`: how many days of files to read, `jot.days` (7) by default.
- `--json`: one JSON line per event.

At a terminal, a trace with several events opens as a list laid out as the tree. Enter on an event prints every field, then offers Back to the trace or Quit. A span ID, or a trace with a single event, prints the tree as above.
