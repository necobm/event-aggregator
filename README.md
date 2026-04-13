# Event Aggregator

A fault-tolerant, horizontally-scalable event loader that pulls events from
multiple remote sources into a centralized store. Designed to run as
**multiple concurrent workers** (possibly on different servers) without ever
requesting the same event twice over the network.

![Overall architecture](doc/overal_architecture_diagram.png)

---

> **IMPORTANT**
> This project is form testing and demo purposes only, not ready for production use.
> Executing the loader worker will throw exceptions because a real database does not exist.
> For validating the functionality, run the tests (`make test`) which use an in-memory fake 
> loader and a real PostgreSQL test database.

## Overview

A single long-running console command (`app:event-loader:run`) coordinates
the whole pipeline:

1. Acquires (and lock) the next available source (not locked by concurrent workers).
2. Fetch the next events batch, using an offset strategy with the last known event ID form that source.
3. Releases the source and moves to the next one, round-robin, forever.
4. Transform the results to match Event contract for this application.
5. Store the events in the local database.


Cross-worker mutual exclusion is enforced at the database (shared across
servers). A 200 ms per-source cooldown is enforced in the same acquire
query. Rate-limiting, timeout bounding, and lock-TTL are wired so that the
spec's invariant — *"the same event is not requested twice"* — holds.

## Tech stack

- **PHP 8.2+**
- **Symfony 7.4** (`console`, `framework-bundle`, `http-client`, `dotenv`, `runtime`)
- **Doctrine ORM 3.6** + **Doctrine Migrations 4**
- **Docker** + **Makefile** for the dev workflow

## Further reading

- [`doc/process-flow.md`](doc/process-flow.md) — full end-to-end flow
  walkthrough with Mermaid diagrams (flowchart, sequence diagram, state
  diagram) and an error-handling matrix.
- [`doc/er-diagram.md`](doc/er-diagram.md) — ER diagram of the `Source` and
  `Event` entities.

---

## Requirements

- Docker (24+)
- GNU Make

That's it — the PHP runtime, Composer, and all extensions are provisioned
inside the container by the [`Dockerfile`](Dockerfile).

---

## Configuration

Two env files ship with the repo:

| File | Purpose                                                                                                                                                                                         |
|---|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [`.env`](.env) | Symfony's default env file. Holds `DATABASE_URL`, `APP_ENV`, etc. Committed.                                                                                                                    |
| [`.env.dev`](.env.dev) | Dev-only overrides. Loaded by the container via `--env-file` (see `Makefile`). Committed **only because this is a demo/test project** — in production, env values live on the host, not in git. |

### Environment variables that matter

| Variable | Default | Where it's used |
|---|---|---|
| `DATABASE_URL` | `postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8` | Doctrine DBAL connection. |
| `SOURCE_A_BASE_URI` | `https://api.sourcea.com` | Base URI for the scoped HTTP client `clientSourceA`. |
| `SOURCE_B_BASE_URI` | `https://api.sourceb.com` | Same, for `clientSourceB`. |
| `SOURCE_C_BASE_URI` | `https://api.sourcec.com` | Same, for `clientSourceC`. |
| `HTTP_CLIENT_TIMEOUT` | `30` (seconds) | Hard timeout on every scoped HTTP client. **Must stay strictly below `LOCK_TTL_SECONDS` (60) in `EventLoaderOrchestratorCommand`** — otherwise the source's soft lock can expire mid-fetch and another worker might re-transport the same events. |

All HTTP-client scoping lives in
[`config/packages/http_client.yaml`](config/packages/http_client.yaml).

---

## Running with the Makefile

Run `make` (or `make help`) to list the available targets:

```
Usage: make [target]

  build    Build the Docker image
  up       Build and start the container (runs composer install on boot)
  down     Stop and remove the container
  shell    Open an interactive shell inside the running container
  logs     Tail the container logs
```

### First-time setup

```bash
make up          # builds the image + starts the container + runs composer install
make shell       # drop into the container
```

You're now inside the container at `/app` with the project mounted as a
bind volume, so edits on the host are reflected live.

### Day-to-day

```bash
make logs        # tail container logs
make down        # stop and remove the container (data volumes are untouched)
make build       # rebuild the image after a Dockerfile change
```

---

## Running the event loader

Once inside the container (`make shell`):

```bash
# Long-running invocation — runs the orchestrator in its infinite loop
bin/console app:event-loader:run
```

The command runs forever. Stop it with `Ctrl-C`.

### Bounding the loop with `--max-iterations`

Pass `--max-iterations=N` to make the orchestrator perform exactly **N** loop
iterations and then exit cleanly. Useful for:

- **Integration tests** — the loop has a deterministic stopping point instead of
  running forever.
- **Graceful restarts** — schedule a worker to do, e.g., `--max-iterations=10000`
  and let a process supervisor (systemd, Kubernetes, supervisord) respawn it
  between batches without sending `SIGTERM` mid-fetch.
- **Local debugging / smoke checks** — run a single iteration end-to-end:

```bash
bin/console app:event-loader:run --max-iterations=1
```

Omit the option to keep the original "run forever" behavior.

---

## Running the tests

The integration test suite exercises `app:event-loader:run` end-to-end against a
real PostgreSQL test database (so `SELECT ... FOR UPDATE` and the 200 ms
cooldown filter execute against the real RDBMS), with external HTTP sources
replaced by an in-memory fake loader.

### One-shot

```bash
make test
```

This runs `vendor/bin/phpunit` inside the `app` container.

### What happens under the hood

- **Test database** — `config/packages/doctrine.yaml` already appends a `_test`
  suffix to the DB name in the `test` env, so tests target `app_test` and never
  touch the dev `app` database.
- **Schema reset** — `tests/bootstrap.php` drops and recreates the schema once
  per phpunit invocation (before any test runs), via `doctrine:schema:drop`
  and `doctrine:schema:create`.
- **Per-test isolation** — `dama/doctrine-test-bundle` wraps every test in a
  single long-lived transaction that is rolled back on teardown, so no rows
  persist between tests.
- **No real HTTP** — `tests/Fake/InMemoryEventLoader.php` is registered as an
  `app.event_loader` only in the `test` env (`config/services.yaml` →
  `when@test`) and is the loader the tests script per scenario.

### First-time setup

The `app_test` database is created automatically by the bootstrap, but if you
ever need to do it manually (e.g. after wiping the postgres volume):

```bash
make shell
bin/console doctrine:database:create --if-not-exists --env=test
```

---

## Project layout

```
src/
├── Command/
│   └── EventLoaderOrchestratorCommand.php   # entry point + main loop
├── Entity/
│   ├── Source.php                           # source state (name, offset, locks)
│   └── Event.php                            # ingested event (unique per source)
├── Repository/
│   └── SourceRepository.php                 # acquireNext() — FOR UPDATE + cooldown
└── Service/
    ├── EventLoaderInterface.php             # fetch/parse/supports contract
    └── EventLoaderSourceA.php               # example implementation

config/packages/
├── doctrine.yaml                            # ORM + DBAL wiring
├── doctrine_migrations.yaml
├── http_client.yaml                         # scoped clients per source
└── framework.yaml

doc/
├── process-flow.md                          # end-to-end flow doc + Mermaid diagrams
├── er-diagram.md                            # Source/Event ER diagram
└── overal_architecture_diagram.png          # the image above

Dockerfile                                   # PHP 8.4 + opcache + composer
Makefile                                     # dev-workflow wrapper
compose.yaml                                 # app + databse services

```

---
