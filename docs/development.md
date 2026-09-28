# Development workflow

Two entry points, both wrappers around `docker compose run --rm php`: `./dev` for any command in the container, `uat/run` for the exploratory scripts in `uat/`.

## ./dev

Runs one command in the PHP container, from the repository root.

```sh
./dev pest                       # the whole test suite
./dev pest --filter=Section      # one file or one test name
./dev phpstan
./dev pint
./dev composer require --dev some/package
./dev php -v
./dev sh                         # a shell, for several commands in a row
```

`pest`, `phpstan` and `pint` expand to `vendor/bin/<name>`. Every other argument passes through untouched.

`PHP_VERSION` works as always, since compose reads it: `PHP_VERSION=8.5 ./dev pest`. See "PHP versions" in the README for the versions available and when to re-run `composer update`.

## uat

`uat/` holds scripts that boot the package outside the test suite, through Testbench, and dump what the code does. They are for behavior that is still being designed, when a wall of dumps says more than an assertion.

```sh
uat/run section         # one script, with or without the .php
uat/run section.php
uat/run                 # every script in uat/, bootstrap.php excluded
```

Each script requires `uat/bootstrap.php`, which creates the Testbench application with `JotServiceProvider` registered. The runner passes `-d zend.assertions=1`, so `assert()` inside a script stops it with an `AssertionError`.

A script covers one theme, and the file name says which: `section.php`, `add.php`.

### wrap()

`uat/bootstrap.php` defines `wrap()`, which labels one step, runs it, then dumps what the accumulator holds: `fields` raw, `sections` flattened the way emit will write them, and `logs`, every log line the step wrote, such as a jot warning.

```php
wrap('a string key', fn () => jot()->add('payment.provider', 'stripe'));

wrap('increment, twice', function () {
    jot()->increment('payment.attempts');
    jot()->increment('payment.attempts');
});

wrap('a section fills over the request', function () {
    jot(PaymentLog::class)->attempts++;
    jot(PaymentLog::class)->status = 'declined';
});

wrap('where we are');   // no step: a snapshot on its own
```

Output for the first one:

```
--- a string key
array:2 [
  "fields" => array:1 [
    "payment.provider" => "stripe"
  ]
  "sections" => []
  "logs" => []
]
```

The accumulator lives for the whole script, so each `wrap()` shows every field written so far, not only the ones from its own step. `logs` is the exception: it holds only what the current step logged.

Two files are committed, the harness: `uat/run` and `uat/bootstrap.php`. The scripts themselves are git-ignored, because they go stale as soon as the behavior they explore settles.

> A behavior that stops moving belongs in `tests/`, as a Pest test. The dumps become expectations, and CI starts guarding it. `uat/` keeps only what is still open.

## Where a check belongs

| The behavior | Where |
|---|---|
| Settled, and must not regress | `tests/`, a Pest test |
| Still being designed, judged by eye | `uat/`, a script with dumps |
| Structural rule across the package | `tests/ArchTest.php` |

`tests/ArchTest.php` forbids `dd`, `dump` and `ray` in the code it scans, which is one more reason for dumps to stay in `uat/`.
