# Development workflow

How to run the tests, try a change on a real app, and release Jot. PHP and Composer run in Docker, so the host needs Docker with the Compose plugin (`docker compose version` prints a version) and no PHP.

## Run the tests

1. Install the dependencies, once:
   ```sh
   ./dev composer install
   ```
2. Run the suite:
   ```sh
   ./dev pest                       # the whole suite
   ./dev pest --filter=Section      # one file or one test name
   ```
3. Before pushing, run static analysis and the formatter:
   ```sh
   ./dev phpstan --memory-limit=2G  # PHPStan with Larastan
   ./dev pint
   ```

> PHPStan's parallel workers run out of memory with the default limit. Pass `--memory-limit=2G`.

The first `./dev` run builds the Docker image, which takes a minute. Later runs reuse it.

### How ./dev works

`./dev <command>` runs one command in a throwaway PHP container, from the repository root. It wraps `docker compose run --rm php`.

- `pest`, `phpstan` and `pint` expand to `vendor/bin/<name>`. Every other argument passes through untouched, so `./dev php -v` and `./dev composer show` work.
- `./dev sh` opens a shell, for several commands in a row.
- `Dockerfile`: FrankenPHP image for the chosen PHP version, plus Composer and the `zip` and `pcov` extensions.
- `compose.yaml`: one service, `php`, that mounts the package folder at `/app` and runs as your user, so created files stay yours.

After a change to `Dockerfile`, rebuild the image:

```sh
docker compose build php
```

> Your user ID is not 1000? Export it first: `export UID GID=$(id -g)`. Otherwise files in `vendor/` belong to another user.

## Run on another PHP version

`PHP_VERSION` picks the PHP version: `8.3` (default), `8.4` or `8.5`. Each version builds its own image, tagged `laravel-wide-events-php:<version>`.

```sh
PHP_VERSION=8.5 ./dev composer update
PHP_VERSION=8.5 ./dev pest
```

> Run `composer update` after switching versions. `composer.lock` is git-ignored, and a lock resolved on one PHP version can fail on another.

## Run on Livewire 3

`composer update` installs Livewire 4. To run the suite on Livewire 3, then go back:

```sh
./dev composer update livewire/livewire --with "livewire/livewire:^3.6" -W
./dev pest
./dev composer update livewire/livewire
```

Tests for Livewire 4 only, such as `livewire.poll`, are skipped on Livewire 3.

## What CI runs

The `run-tests` workflow (`.github/workflows/run-tests.yml`) runs on pull requests and on pushes to `develop` that touch PHP or Composer files. It runs three combinations:

| PHP | Laravel | Livewire | Dependencies |
|---|---|---|---|
| 8.3 | 11 | 3 | lowest |
| 8.4 | 12 | 3 | stable |
| 8.5 | 13 | 4 | stable |

The `phpstan` workflow runs static analysis.

## Measure coverage

The image ships with the `pcov` extension, so coverage needs no setup:

```sh
./dev pest --coverage
```

It prints the coverage of each class in `src/`. An image built before `pcov` joined the `Dockerfile` lacks it: rebuild with `docker compose build php`.

## Try a change in the workbench

The workbench is a small Laravel app, built with Orchestra Testbench Workbench, that runs the package against real requests, jobs and logins.

- `testbench.yaml`: providers, route and command discovery, database build steps.
- `workbench/routes/web.php`: one route per scenario step.
- `workbench/app/Console/Commands/DemoCommand.php`: `jot:demo`, which runs the scenarios.
- `workbench/app/Jot`: sections, as [Where sections live](fields.md#where-sections-live) recommends for an app.

### Setup

Run once, and again after each `composer install` or `composer update`, which reset the testbench skeleton, database included:

```sh
./dev php vendor/bin/testbench workbench:build
```

It creates an SQLite database, runs the Laravel default migrations (users, cache, jobs) and seeds the user `demo@example.com` with the password `password`.

> Run Composer through `./dev` so it resolves for the container's PHP version.

### Scenarios

`jot:demo` sends each request through the HTTP kernel in the same process, so no server is needed. It prints every event the scenario wrote to the log.

```sh
./dev php vendor/bin/testbench jot:demo           # list the scenarios
./dev php vendor/bin/testbench jot:demo booking   # one scenario
./dev php vendor/bin/testbench jot:demo all
```

| Scenario | What it shows |
|---|---|
| `booking` | The four requests of [Paid event booking](examples.md#paid-event-booking), with a `PaymentLog` section and enums |
| `job-failed` | A request queues a job, `queue:work --stop-when-empty` runs it, the job fails |
| `job-sync` | A sync job inside a request: `parent_span_id` and `trace_id` point to the request |
| `login` | `auth.result=success` and `user.id` |
| `login-failed` | `auth.result=failed`, no `user.id` |
| `error` | An exception, answered with a 500 |
| `query` | `jot:query` on the trace of a failed job, then on the job's span |
| `livewire` | A Livewire form: the page load, then three updates (next step, failed validation, submit) with `livewire.*` fields |

### Server

1. Start the server:
   ```sh
   ./dev php vendor/bin/testbench serve --host=0.0.0.0 --port=8000
   ```
   `./dev` publishes port 8000 for this command only.
2. Call a route from the host: `curl http://localhost:8000/events/42`.
3. For the queued job, run a worker in another terminal: `./dev php vendor/bin/testbench queue:work`.
4. Stop the server with Ctrl+C.

### Output

Events go to the default Jot output, a daily JSON file:

```sh
tail -f vendor/orchestra/testbench-core/laravel/storage/logs/jot-$(date -u +%F).log
```

`jot:demo` prints the path at the end of each run.

### New scenario

1. Add the route to `workbench/routes/web.php`, with a name, since `http.route` holds the route name.
2. Add the scenario name and description to `SCENARIOS` in `DemoCommand`.
3. Add a `run<Name>()` method that calls `$this->send()` once per request, for example `runJobSync()` for `job-sync`.
4. Run `./dev php vendor/bin/testbench jot:demo <name>` to check the events.

## Record the GIFs

The GIFs in `docs/images`, used by the README and [Querying events](querying.md), come from the [VHS](https://github.com/charmbracelet/vhs) tapes in `docs/tapes`. Render them on the host, which needs `vhs` and PHP with `pdo_sqlite`:

```sh
composer docs:tapes
```

> The tapes delete the workbench log files, then fill them with new demo events.

## Where a check belongs

| The behavior | Where |
|---|---|
| Settled, and must not regress | `tests/`, a Pest test |
| Judged by eye, on real requests and jobs | the workbench, a `jot:demo` scenario |
| Structural rule across the package | `tests/ArchTest.php` |

`tests/ArchTest.php` forbids `dd`, `dump` and `ray` in the code it scans.

## Documentation

The README is for app developers who have never read it: what Jot is and costs, install, debug an incident, add fields, volume, Sentry. Reference material lives in `docs/`: fields, querying, configuration, Livewire, outputs, examples. `architecture.md` holds the mechanism only, the README the user-facing fact.

When editing:

- One home per fact. The list of always-kept events lives in "Sampling" in the README, other places link to it.
- `docs/configuration.md` holds `config/jot.php` verbatim, minus `<?php`. Update both together.
- Comments in `src/` and `config/jot.php` point to headings by name ("Sampling", "Levels and volume", "Key collisions", "Flattening rules", "Context"). Renaming a heading means `grep -rn "README\|docs/" src config`.
- The README Contents lists every H2 and H3. Check anchors after renaming one.
- State behaviour only after checking it in `src/`, and numbers only when measured. The disk table uses about 950 bytes per event, measured in one app.
- An upgrade step goes to the CHANGELOG entry of the release and to "Upgrading" in the README.

To review a large change, ask readers who never saw the docs: a junior installing Jot, a lead evaluating it, an on-call developer debugging an error, an editor checking the prose.

## Releases

Versions come from git tags, `v` plus a semantic version, for example `v0.1.0`. Composer reads them directly, so `composer.json` holds no `version` field. On each pushed tag, the `release` workflow (`.github/workflows/release.yml`) runs the test suite, then creates the GitHub release with the notes of `bin/release-notes`.

1. Start the release from `develop`:
   ```sh
   git flow release start 0.1.0
   ```
2. Preview the notes. The preview lists the commits only: the `CHANGELOG.md` section joins the notes once the tag exists.
   ```sh
   bin/release-notes HEAD
   ```
3. Optional: add a `## 0.1.0` section to `CHANGELOG.md` for what commit subjects cannot say, such as how to upgrade past a breaking change. Commit it.
4. Finish. git-flow merges into `master` and `develop`, and tags `v0.1.0`.
   ```sh
   git flow release finish 0.1.0
   ```
5. Push:
   ```sh
   git push origin master develop && git push --tags
   ```

`bin/release-notes` writes the notes: the `CHANGELOG.md` section of the version, then the commit subjects since the previous tag, grouped by Conventional Commits type:

| Commit type | Section |
|---|---|
| any type with `!`, such as `feat!:` | Breaking changes |
| `feat:` | Features |
| `fix:` | Fixes |
| `docs:` | Documentation |

Other types, such as `build:`, `test:` or `style:`, stay out of the notes. A commit subject is a line in the release notes, so write it for the people who install the package.

> Until `1.0.0`, bump the minor version (`0.1` to `0.2`) for a breaking change and the patch version for anything else, see [Versioning](../README.md#versioning).

### Install from another project

Set up the token and the repository as in [Installation](../README.md#installation). Then:

- A release: `composer require marvelous-digital/laravel-wide-events:^0.3`
- Unreleased work on `develop`: `composer require marvelous-digital/laravel-wide-events:dev-develop`
