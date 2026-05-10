# Idempotent webhook receiver

A small Symfony 7 application that demonstrates how to receive webhooks from third-party
providers (Stripe, Mailgun, etc.) safely and idempotently. Built as a code sample to
show architectural choices around async processing, signature verification, and
deduplication.

## The problem this solves

Webhooks look easy from the outside — accept a POST, parse the JSON, do some work, return
200. Three things make that naive shape dangerous in production:

1. **Providers retry aggressively.** Stripe will retry a failed delivery for up to three
   days; a single timeout on our side cascades into 5+ duplicate deliveries of the same
   `payment_intent.succeeded` event. Without deduplication you charge the customer's order
   five times.
2. **The 10–30 second budget is a security feature.** If our handler does its work
   synchronously and crawls under load, the provider times out, retries, and we double-process.
   Long handlers don't just time out the request — they manufacture the duplicate problem.
3. **Signatures matter.** Without verification, anyone who learns our webhook URL can mint
   fake `payment_intent.succeeded` events and trigger fulfilment for free. The verification
   has to be done correctly: on the raw body bytes, with constant-time comparison, with
   replay-window checks.

This sample addresses all three: a fast inbox controller (verify → dedupe → persist →
acknowledge in <100ms), an async worker for the actual processing, and a strict signature
verifier per provider.

## Architecture at a glance

```
HTTP POST /webhooks/{provider}
       │
       ▼
┌─────────────────────────┐
│   WebhookController     │
│  ① resolve provider     │  ─────► 404 if unknown
│  ② verify signature     │  ─────► 401 if invalid (audited)
│  ③ parse payload        │  ─────► 400 if malformed
│  ④ DB transaction:      │
│     ┌─────────────────┐ │
│     │ INSERT          │ │
│     │ processed_event │ │  ─────► 200 duplicate on unique-violation
│     │ INSERT          │ │
│     │ received_webhook│ │  (audit)
│     │ DISPATCH        │ │  (transactional outbox: queue + row commit atomically)
│     │ ProcessWebhook  │ │
│     └─────────────────┘ │
└─────────────────────────┘
       │  returns 200 in <100ms
       ▼
┌─────────────────────────┐
│   Messenger queue       │  (Doctrine transport — same DB, no new infra)
└─────────────────────────┘
       │
       ▼
┌─────────────────────────┐
│  ProcessWebhookHandler  │
│  • find EventProcessor  │
│  • run it               │  ─────► COMPLETED
│  • update status        │  ─────► SKIPPED (no processor for this event type)
│                         │  ─────► FAILED  (retry 3× w/ backoff → failure transport)
└─────────────────────────┘
```

## Key design decisions

- **Database-backed deduplication via unique constraint, not Redis.** A unique index on
  `(provider, external_event_id)` is durable, queryable, and provides a permanent audit
  trail. Adding Redis would mean a second source of truth, an extra operational dependency,
  and the loss of the audit benefit. Above ~1000 webhooks/sec we'd revisit this — most B2B
  integrations live well below that and a `UniqueConstraintViolationException` is plenty
  fast.

- **Transactional outbox: persist + dispatch in one Doctrine transaction.** The dedup row,
  the audit row, and the queue insert all commit together. If the bus dispatch fails
  (transport down, deadlock), the dedup row rolls back too — the provider's retry is
  treated as a fresh delivery, not silently lost behind a duplicate response.

- **Synchronous response, async processing.** Providers timeout in 10–30 seconds. Doing
  real work synchronously means timeouts under load, which means more retries, which means
  more duplicates. The controller's only job is to durably accept and acknowledge fast.
  Real processing happens in workers via Symfony Messenger.

- **Per-provider signature verification.** Each provider has its own quirks — Stripe uses
  `t=...,v1=...` with replay protection; Mailgun uses plain HMAC over the body. The
  `SignatureVerifierInterface` lets each provider plug in its own verification logic
  without leaking that knowledge into the controller.

- **Constant-time signature comparison (`hash_equals`).** Naïve `===` leaks timing
  information that can be used to forge signatures byte-by-byte. A small detail with
  large implications; the unit tests assert the source uses `hash_equals` so a regression
  to `==` would fail CI.

- **Replay-window protection is two-sided.** Stripe-style timestamp rejection guards both
  past replays and future-dated signatures (a stolen-secret attacker could otherwise mint
  signatures dated years ahead and bypass any retention-based defence).

- **Tagged services for the registries.** Adding a new provider or event processor is
  exactly one new class — Symfony's autoconfigured tagged-iterator picks it up. No central
  registration list, no YAML edits, no controller changes.

- **Two tables: `processed_event` (dedup) and `received_webhook` (audit).** Keeping them
  separate lets us trim audit rows aggressively (e.g., 90 days) while keeping dedup state
  for the full provider-retry window (Stripe: 3 days; longer is safer). One table would
  force a bad tradeoff between disk usage and replay safety.

- **PHP backed enum for status; UUID v7 for ids.** Modern PHP. UUID v7 gives time-ordered
  ids that don't leak business volume the way auto-increments do, and they sort naturally
  for pagination.

- **Audit log captures invalid-signature attempts.** Repeated `signature_valid=false` rows
  from one IP is a security signal worth surfacing. Keeping them in the same audit table
  as legitimate traffic means one query to investigate.

## Quick start

The repo ships with a Docker Compose setup so reviewers don't need a local PHP install.
Three commands and the receiver is up:

```bash
make up         # build + start php-fpm + nginx (one-time build, ~30s)
make install    # composer install inside the container
make migrate    # create the SQLite schema
```

The HTTP server is now on http://127.0.0.1:8000. To run the async worker in a second
terminal so you can watch processing live:

```bash
make worker     # foreground; Ctrl+C to stop
```

`make help` lists every target. `make down` stops the stack.

### Without Docker

If you have PHP 8.3 + Composer locally:

```bash
composer install
php bin/console doctrine:migrations:migrate -n
php -S 127.0.0.1:8000 -t public          # terminal 1
php bin/console messenger:consume webhooks -vv   # terminal 2
```

SQLite ships with PHP and the Doctrine messenger transport reuses the same database, so
nothing else needs to be installed — no Redis, no MySQL.

## Manual testing

See **[MANUAL-TESTING.md](MANUAL-TESTING.md)** for a 10-minute, copy-paste tour of every
behaviour: happy path, idempotent replay, forged signature, replay-window expiry, unknown
provider, malformed JSON, second-provider integration (Mailgun), async failure + retry,
and operator inspection.

## Running the test suite

Inside the compose env:

```bash
make test       # phpunit — 36 tests, ~100ms
make stan       # phpstan level 8 — zero errors
make cs         # php-cs-fixer — Symfony preset, snake_case test names
```

Or directly: `docker compose exec php vendor/bin/phpunit` etc. Coverage on the critical
paths (controller, signature verifiers, providers, message handler) is between 83% and
100%.

## Adding a new provider

Three steps, ~5 minutes of work:

1. Implement `App\Provider\WebhookProviderInterface` (e.g., `PaddleProvider`). Either
   reuse `App\Signature\HmacSignatureVerifier` with a different header name, or write a
   provider-specific verifier.
2. Add `WEBHOOK_PADDLE_SECRET` to `.env` and inject it into your provider via the
   `#[Autowire]` attribute, mirroring `StripeProvider`.
3. (Optional) Implement `App\EventProcessor\EventProcessorInterface` for any event types
   you care about. Without a processor, events are received and acknowledged but recorded
   as `SKIPPED` — exactly the right behaviour for the "events we don't care about" case.

The autoconfigured tags (`app.webhook_provider`, `app.event_processor`) wire everything up
on the next container compile. No registry edits.

## What's intentionally not included

Be honest about scope:

- **No Redis or AMQP transport.** The Doctrine transport works fine for moderate volume
  and demonstrates the architecture without a second piece of infrastructure. At higher
  throughput we'd switch — Symfony Messenger makes that a one-line config change.
- **No metrics or observability layer.** In production we'd export Prometheus counters for
  received / processed / failed by provider, and histograms for processing latency. Adding
  the StatsD or OpenTelemetry bridge is a bundle install, not a refactor.
- **No payload-schema validation.** Provider schemas evolve and we don't want to gate on
  every minor change. The receiver extracts only what it needs (`event_id`, `event_type`)
  and hands the raw decoded payload to the processor — domain validation lives there.
- **The event processors are deliberately minimal.** They're integration seams. In a real
  app a `PaymentSucceededProcessor` would update an `Order` aggregate, dispatch a
  `SendReceiptEmail` message, kick off fulfilment — but that's the application's job, not
  the webhook framework's.
- **No admin UI or replay endpoint.** `php bin/console app:webhooks:list --status=failed`
  + `messenger:consume failed` covers the operational case without UI plumbing.

## Project structure

```
src/
├── Controller/        # WebhookController — the inbox endpoint
├── Entity/            # ProcessedEvent (dedup), ReceivedWebhook (audit)
├── Enum/              # ProcessedEventStatus
├── EventProcessor/    # Domain handlers per (provider, event-type)
├── Exception/         # InvalidSignature, MalformedPayload, UnknownProvider
├── Message/           # ProcessWebhook (the queued command)
├── MessageHandler/    # ProcessWebhookHandler (worker-side state machine)
├── Provider/          # Stripe, Mailgun + the registry
├── Repository/        # Doctrine repositories
└── Signature/         # SignatureVerifierInterface + Stripe / generic HMAC

tests/                 # mirror of src/, plus tests/bootstrap.php for schema setup
migrations/            # one migration: initial schema
config/                # standard Symfony config; messenger.yaml has the retry strategy
docker/                # Dockerfile + nginx config used by docker-compose.yml
bin/sign-payload.php   # dev helper for hand-crafting valid signatures (NOT for prod)
```

## Tech stack

- PHP 8.3, Symfony 7.2 (runtime, framework-bundle, console, messenger, dotenv, serializer, uid)
- Doctrine ORM 3 + Doctrine Migrations + Doctrine messenger transport
- SQLite by default — swap to MySQL or Postgres by changing `DATABASE_URL` in `.env.local`,
  no code changes
- PHPUnit 11, PHPStan level 8, php-cs-fixer with the Symfony preset
- DAMA\DoctrineTestBundle for transactional test isolation

## License

MIT.
