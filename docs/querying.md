# Querying events

`php artisan jot:query` finds wide events in the log files of the server it runs on. Run it on the server that handled the request. The README covers the commands used most, see [Reading events](../README.md#reading-events).

## Contents

- [Find one request](#find-one-request)
- [Filter](#filter)
- [Follow live](#follow-live)
- [JSON for scripts](#json-for-scripts)
- [Browsing](#browsing)
- [Options](#options)
- [Where it reads](#where-it-reads)

| Command | Prints |
|---|---|
| `jot:query` | the most recent events, with filters |
| `jot:query <trace_id>` | every event of one trace, as a tree |
| `jot:query <span_id>` | the trace tree, then every field of that one event |

A trace ID (32 hex characters) groups a request with the jobs it queued. A span ID (16 hex characters) names one event. Both appear on every line `jot:query` prints.

At a terminal, `jot:query` opens an interactive table, see [Browsing](#browsing). Piped, in CI (continuous integration), with `-n` (`--no-interaction`) or with `--json`, it prints plain lines, as in the samples below.

## Find one request

1. Get the trace ID. The response header `X-Request-Id` holds it (the name is set by `trace.header` in `config/jot.php`). Without it, find the request by its fields, see [Filter](#filter):
   ```sh
   php artisan jot:query --where=http.route=jobs.failing --since=1h
   ```
   ```
   12:36:19  59fa87208b9aaecf63c39f0506dc663a  http  0b700b4686654677  POST jobs.failing 200  20ms
   Run jot:query <trace_id> for a trace, or <span_id> for every field.
   ```
   Each line holds the time, the trace ID, the entry point (`http`, `queue`, `console` or `schedule`), the span ID, then what ran, its status, its duration and its error.
2. Print the trace. Each event sits under the event that started it (its `parent_span_id`), so a queued job sits under the request:
   ```sh
   php artisan jot:query 59fa87208b9aaecf63c39f0506dc663a
   ```
   ```
   Trace 59fa87208b9aaecf63c39f0506dc663a, 2 event(s)
   http  0b700b4686654677  POST jobs.failing 200  20ms
   └─ queue  73e837d98f1733c0  ChargeCard failed  9ms  RuntimeException: Card network timeout
   Run jot:query with a span ID for every field of that event.
   ```
3. Print every field of one event with its span ID. The tree comes first, `◀` marks the event:
   ```sh
   php artisan jot:query 73e837d98f1733c0
   ```
   ```
   Trace 59fa87208b9aaecf63c39f0506dc663a, 2 event(s)
   http  0b700b4686654677  POST jobs.failing 200  20ms
   └─ queue  73e837d98f1733c0  ChargeCard failed  9ms  RuntimeException: Card network timeout ◀

     73e837d98f1733c0 ...........................................................
     trace_id .................................. 59fa87208b9aaecf63c39f0506dc663a
     span_id ................................................... 73e837d98f1733c0
     parent_span_id ............................................ 0b700b4686654677
     entrypoint ........................................................... queue
     job.status .......................................................... failed
     ...
   ```

With an ID, `jot:query` reads `jot.days` (7) days of files. It accepts `--days` and `--json` only, and refuses the filters.

When nothing matches, it names the files it read:

```
WARN  No event with ID ffffffffffffffffffffffffffffffff in the last 7 days of: storage/logs/jot-2026-10-13.log.
```

Check that the request reached this server and that the event was kept, see [Troubleshooting](../README.md#troubleshooting) for the usual causes.

## Filter

Without an ID, `jot:query` lists the last 20 matching events of the files written in the last day, oldest first. Filters combine: an event must match all of them.

```sh
php artisan jot:query --errors                                     # level error: error.type, 5xx status or failed job
php artisan jot:query --slow                                       # slower than sampling.slow_ms (1000 ms)
php artisan jot:query --since=15m --limit=50
php artisan jot:query --where='http.route=events.*' --where=user.id=42
php artisan jot:query --where=entrypoint=queue,schedule             # jobs and scheduled tasks
```

With `--errors`:

```
12:36:19  59fa87208b9aaecf63c39f0506dc663a  queue  73e837d98f1733c0  ChargeCard failed  9ms  RuntimeException: Card network timeout
Run jot:query <trace_id> for a trace, or <span_id> for every field.
```

`--where` takes `key=value` on the flat field names of [Fields](fields.md):

- `*` is a wildcard: `--where='http.route=events.*'`.
- Commas list alternatives: `--where=entrypoint=http,queue`.
- A list field, such as `livewire.calls`, is compared as JSON, so wrap the pattern in `*`: `--where='livewire.calls=*::submit*'`.
- An event without the field never matches.

## Follow live

`--follow` prints the matching events, then each new one as it is written, until Ctrl+C. It prints plain lines, even at a terminal, and takes the same filters:

```sh
php artisan jot:query --follow --errors
```

For a live stream of all log messages, Jot events included, run `php artisan pail`.

## JSON for scripts

`--json` prints one JSON object per line, with flat field names, and no hint line. It works with a list, a trace or `--follow`:

```sh
php artisan jot:query --where=entrypoint=queue --limit=1 --json
```

```json
{"trace_id":"59fa87208b9aaecf63c39f0506dc663a","span_id":"73e837d98f1733c0","parent_span_id":"0b700b4686654677","timestamp":"2026-10-13T12:36:19+00:00","entrypoint":"queue","duration_ms":9,"job.name":"Workbench\\App\\Jobs\\ChargeCard","job.status":"failed","error.type":"RuntimeException","exception.message":"Card network timeout"}
```

The sample is shortened: a real line holds every field of the event. Pipe it to `jq`, for example `| jq -r '.["job.name"]'`.

## Browsing

At a terminal, the recent events open in a table, newest at the top:

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

| Key | Does |
|---|---|
| Arrow keys | move |
| `/` | search the rows, trace and span IDs included |
| `r` | reload the events |
| Enter | print every field of the event |

After the fields, pick Back to the list, Show the trace or Quit. Show the trace lists the events of the trace as a tree, and Enter on any of them prints its fields. The table takes the same filters as the plain list.

A trace ID with several events opens the same tree list. A span ID, or a trace with one event, prints as in [Find one request](#find-one-request).

## Options

| Option | Default | Does |
|---|---|---|
| `--limit` | `20` | how many recent events to list |
| `--where` | none | `key=value` field filter, repeatable, see [Filter](#filter) |
| `--errors` | off | only events at level `error`: an `error.type`, a 5xx status or a failed job |
| `--slow` | off | only events slower than `sampling.slow_ms` (1000 ms) |
| `--since` | none | only events newer than a number and a unit (`s`, `m`, `h` or `d`), such as `15m`. Reads more days of files when needed |
| `--days` | `1` without an ID, `jot.days` (7) with one | how many days of files to read, by modification time |
| `--follow` | off | keep printing new events, see [Follow live](#follow-live) |
| `--json` | off | one JSON line per event, see [JSON for scripts](#json-for-scripts) |

Only `--days` and `--json` work with an ID.

## Where it reads

`jot:query` reads the outputs that write files: the default Jot file and any `single` or `daily` log channel. An event written to two of them prints once. For the other outputs, it prints a hint instead:

- Sentry Logs: `Also in Sentry Logs.`, and with an ID, `Also in Sentry Logs: search trace_id:<trace_id>.`
- Any other channel, such as `stderr`: `Also written to the <channel> channel, which jot:query cannot read.`

See [Outputs](outputs.md) to configure them.
