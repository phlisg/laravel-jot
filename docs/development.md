# Development workflow

PHP and Composer run in Docker, so the host needs no PHP. `./dev` wraps `docker compose run --rm php` and runs any command in the container.

## Docker

Requirements: Docker with the Compose plugin (`docker compose version` prints a version).

- `Dockerfile`: FrankenPHP image for the chosen PHP version, plus Composer and the `zip` extension.
- `compose.yaml`: one service, `php`, that mounts the package folder at `/app` and runs as your user, so created files stay yours.
- `docker compose run --rm php <command>`: starts a throwaway container, runs the command in `/app`, then removes the container.

The first run builds the image, which takes a minute. Later runs reuse it. After a change to `Dockerfile`, rebuild it: `docker compose build php`.

> Your user ID is not 1000? Export it first: `export UID GID=$(id -g)`. Otherwise files in `vendor/` belong to another user.

## ./dev

Runs one command in the PHP container, from the repository root.

```sh
./dev composer install
./dev pest                       # the whole test suite
./dev pest --filter=Section      # one file or one test name
./dev phpstan --memory-limit=2G  # static analysis, PHPStan with Larastan
./dev pint                       # format the code
./dev php -v
./dev sh                         # a shell, for several commands in a row
```

`pest`, `phpstan` and `pint` expand to `vendor/bin/<name>`. Every other argument passes through untouched.

> PHPStan's parallel workers run out of memory with the default limit. Pass `--memory-limit=2G`.

## PHP versions

`PHP_VERSION` picks the PHP version: `8.3` (default), `8.4` or `8.5`. Each version builds its own image, tagged `laravel-wide-events-php:<version>`.

```sh
PHP_VERSION=8.5 ./dev composer update
PHP_VERSION=8.5 ./dev pest
```

> Run `composer update` after switching versions. `composer.lock` is git-ignored, and a lock resolved on one PHP version can fail on another.

The GitHub Actions workflow covers PHP 8.3 to 8.5 and Laravel 11 to 13.

## Workbench

The workbench is a small Laravel app, built with Orchestra Testbench Workbench, that runs the package against real requests, jobs and logins.

- `testbench.yaml`: providers, route and command discovery, database build steps.
- `workbench/routes/web.php`: one route per scenario step.
- `workbench/app/Console/Commands/DemoCommand.php`: `jot:demo`, which runs the scenarios.
- `workbench/app/Jot`: sections, as the README recommends for an app.

### Setup

Run once, and again after `composer install` or `composer update`:

```sh
./dev php vendor/bin/testbench workbench:build
```

It creates an SQLite database, runs the Laravel default migrations (users, cache, jobs) and seeds the user `demo@example.com` with the password `password`.

### Scenarios

`jot:demo` sends each request through the HTTP kernel in the same process, so no server is needed. It prints every event the scenario wrote to the log.

```sh
./dev php vendor/bin/testbench jot:demo           # list the scenarios
./dev php vendor/bin/testbench jot:demo booking   # one scenario
./dev php vendor/bin/testbench jot:demo all
```

| Scenario | What it shows |
|---|---|
| `booking` | The four requests of [Example: paid event booking](../README.md#example-paid-event-booking), with a `PaymentLog` section and enums |
| `job-failed` | A request queues a job, `queue:work --stop-when-empty` runs it, the job fails |
| `job-sync` | A sync job inside a request: `parent_span_id` and `trace_id` point to the request |
| `login` | `auth.result=success` and `user.id` |
| `login-failed` | `auth.result=failed`, no `user.id` |
| `error` | An exception, answered with a 500 |
| `query` | `jot:query` on the trace of a failed job, then on the job's span |
| `livewire` | A Livewire form: the page load, then three updates (next step, failed validation, submit) with `livewire.*` fields |

> After `composer update`, run `./dev php vendor/bin/testbench workbench:build`: the update resets the testbench skeleton, database included. Run Composer through `./dev` so it resolves for PHP 8.3.

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

### Terminal recordings

`composer docs:tapes` renders the README GIFs in `docs/images` from the [VHS](https://github.com/charmbracelet/vhs) tapes in `docs/tapes`. Run it on the host, not through `./dev`: it needs `vhs` and PHP with `pdo_sqlite`, and it replaces the events in the workbench log.

### New scenario

1. Add the route to `workbench/routes/web.php`, with a name, since `http.route` holds the route name.
2. Add the scenario name and description to `SCENARIOS` in `DemoCommand`.
3. Add a `run<Name>()` method that calls `$this->send()` once per request, for example `runJobSync()` for `job-sync`.
4. Run `./dev php vendor/bin/testbench jot:demo <name>` to check the events.

## Where a check belongs

| The behavior | Where |
|---|---|
| Settled, and must not regress | `tests/`, a Pest test |
| Judged by eye, on real requests and jobs | the workbench, a `jot:demo` scenario |
| Structural rule across the package | `tests/ArchTest.php` |

`tests/ArchTest.php` forbids `dd`, `dump` and `ray` in the code it scans.

## Releases

Versions come from git tags, `v` plus a semantic version, for example `v0.1.0`. Composer reads them directly, so `composer.json` holds no `version` field. The `release` workflow (`.github/workflows/release.yml`) runs the test suite on each pushed tag, then creates the GitHub release.

1. Start the release from `develop`: `git flow release start 0.1.0`.
2. Preview the notes: `bin/release-notes HEAD`. The preview lists the commits only: the `CHANGELOG.md` section joins the notes once the tag exists.
3. Optional: add a `## 0.1.0` section to `CHANGELOG.md` for what commit subjects cannot say, such as how to upgrade past a breaking change. Commit it.
4. Finish: `git flow release finish 0.1.0`. git-flow merges into `master` and `develop`, and tags `v0.1.0`.
5. Push: `git push origin master develop && git push --tags`.

`bin/release-notes` writes the notes: the `CHANGELOG.md` section of the version, then the commit subjects since the previous tag, grouped by Conventional Commits type:

- `feat!:`, `fix!:` and any type with `!`: "Breaking changes"
- `feat:`: "Features"
- `fix:`: "Fixes"
- `docs:`: "Documentation"

Other types, such as `build:`, `test:` or `style:`, stay out of the notes. A commit subject is a line in the release notes, so write it for the people who install the package.

> Until `1.0.0`, bump the minor version (`0.1` to `0.2`) for a breaking change and the patch version for anything else, see [Versioning](../README.md#versioning).

### Install from another project

Set up the token and the repository as in [Installation](../README.md#installation). Then:

- A release: `composer require marvelous-digital/laravel-wide-events:^0.2`
- Unreleased work on `develop`: `composer require marvelous-digital/laravel-wide-events:dev-develop`
