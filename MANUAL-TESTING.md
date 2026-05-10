# Manual testing guide

A copy-paste tour of every behaviour the receiver implements. No need to run the test suite —
work through this file and you'll see all nine scenarios in roughly ten minutes.

## Prerequisites

- Docker (Desktop on macOS/Windows, or Engine + Compose v2 on Linux)
- `curl` and `openssl` (standard on macOS / Linux)

That's it — no PHP, Composer, or Symfony CLI installation required. SQLite + the Doctrine
messenger transport ride along inside the container, so there's no MySQL or Redis either.

## One-time setup

From the project root:

```bash
make up         # build the php image and start php-fpm + nginx (~30s first time)
make install    # composer install inside the php container
make migrate    # create the SQLite schema
```

Override the signing secrets locally so the curl examples below match. **`.env.local` is
gitignored** — never commit real secrets.

```bash
cat > .env.local <<'EOF'
WEBHOOK_STRIPE_SECRET=whsec_test_secret_for_manual_testing
WEBHOOK_MAILGUN_SECRET=mailgun_test_secret_for_manual_testing
EOF
```

The HTTP server is now on **http://127.0.0.1:8000**. Open **two terminals** at the project
root:

**Terminal A** runs your curls and `make` commands. The HTTP container is in the background
already; `make logs` tails its output.

**Terminal B** runs the async worker in the foreground so you can read processor logs:

```bash
make worker
```

> **Convention.** All commands below use `docker compose exec php …` to invoke things
> inside the container. Payload files live under `var/` because that directory is bind-
> mounted into the container at `/var/www/html/var/`, so the host and container can both
> see them. If you have a host PHP install, you can drop the `docker compose exec php`
> prefix and use `php` directly.

---

## Scenario 1 — Successful Stripe webhook (happy path)

The everyday case: a real provider sending a real event with a valid signature. Demonstrates
fast accept + async hand-off.

```bash
# Sample payload — written under var/ so the container can read it via the bind mount
cat > var/stripe-payment.json <<'JSON'
{
  "id": "evt_test_payment_001",
  "type": "payment_intent.succeeded",
  "data": {
    "object": {
      "id": "pi_test_001",
      "amount": 4999,
      "currency": "chf"
    }
  }
}
JSON

# Compute a valid signature using the helper (inside the container)
SIG=$(docker compose exec -T php php bin/sign-payload.php \
  --provider=stripe \
  --payload-file=/var/www/html/var/stripe-payment.json \
  --secret=whsec_test_secret_for_manual_testing)

# Send the webhook
curl -i -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: $SIG" \
  --data-binary @var/stripe-payment.json
```

**Expected:** `200 OK` with body

```json
{"status":"accepted","event_id":"evt_test_payment_001"}
```

In **Terminal B** (worker) you'll see a `stripe payment succeeded` log line within ~1s.

**Verify the persisted state:**

```bash
docker compose exec php php bin/console app:webhooks:list
```

The row should be `COMPLETED` with a `processed_at` timestamp.

---

## Scenario 2 — Idempotent replay (the headline feature)

Re-run the **exact same** curl from Scenario 1. Real providers do this — a network blip
between us and them, or our previous response taking too long, both trigger a retry.

```bash
curl -i -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: $SIG" \
  --data-binary @var/stripe-payment.json
```

**Expected:** `200 OK` with body

```json
{"status":"duplicate","event_id":"evt_test_payment_001"}
```

**No new line in Terminal B.** The processor wasn't called again — the unique constraint on
`(provider, external_event_id)` short-circuited the request before it ever reached the queue.

```bash
docker compose exec php php bin/console app:webhooks:list
# → still ONE row for evt_test_payment_001, attempt_count=1
```

---

## Scenario 3 — Forged signature (security)

An attacker trying to inject a fraudulent payment by reusing a captured signature against
a swapped body. The signature is computed over the raw body, so any tamper invalidates it.

```bash
cat > var/stripe-tampered.json <<'JSON'
{
  "id": "evt_test_payment_002",
  "type": "payment_intent.succeeded",
  "data": {"object": {"id": "pi_FORGED", "amount": 999999, "currency": "chf"}}
}
JSON

# Re-use $SIG from Scenario 1 — it was signed for var/stripe-payment.json, not the tampered body
curl -i -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: $SIG" \
  --data-binary @var/stripe-tampered.json
```

**Expected:** `401 Unauthorized` with body `{"error":"invalid signature"}`.

The attempt is recorded in the audit log even though no `processed_event` row was created
— security teams want to see repeated invalid signatures from the same IP.

```bash
docker compose exec php php bin/console dbal:run-sql \
  "SELECT provider, signature_valid, ip_address, datetime(received_at) AS at
   FROM received_webhook ORDER BY received_at DESC LIMIT 5"
```

You'll see the failed attempt with `signature_valid = 0`, the IP, and the timestamp.

---

## Scenario 4 — Replay window protection

Sign the same payload but with a timestamp ten minutes in the past. Stripe's spec rejects
anything outside a configurable tolerance (default 5 minutes here) to defeat replay attacks
that capture and hold valid signatures.

```bash
SIG_OLD=$(docker compose exec -T php php bin/sign-payload.php \
  --provider=stripe \
  --payload-file=/var/www/html/var/stripe-payment.json \
  --secret=whsec_test_secret_for_manual_testing \
  --timestamp=$(($(date +%s) - 600)))

curl -i -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: $SIG_OLD" \
  --data-binary @var/stripe-payment.json
```

**Expected:** `401 Unauthorized`. The signature is mathematically valid, but the timestamp
is outside the window — same outcome as a forged signature, by design.

> The tolerance is configurable via `WEBHOOK_REPLAY_TOLERANCE` in `.env` (seconds). The
> protection is two-sided: future-dated signatures are rejected too.

---

## Scenario 5 — Unknown provider

A misconfigured webhook URL or a typo. We return 404 fast with no DB writes — there's no
defensible reason for the receiver to do work for a provider it doesn't know.

```bash
curl -i -X POST http://127.0.0.1:8000/webhooks/paypal \
  -H "Content-Type: application/json" -d '{}'
```

**Expected:** `404 Not Found` with body `{"error":"unknown provider"}`.

---

## Scenario 6 — Malformed JSON

A genuinely broken body. We return 400 (NOT 5xx) so the provider doesn't keep retrying
something that will never parse.

```bash
# Need a valid signature so we get past signature verification first
echo -n 'not even close to json' > var/garbage
SIG_GARBAGE=$(docker compose exec -T php php bin/sign-payload.php \
  --provider=stripe \
  --payload-file=/var/www/html/var/garbage \
  --secret=whsec_test_secret_for_manual_testing)

curl -i -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: $SIG_GARBAGE" \
  --data-binary @var/garbage
```

**Expected:** `400 Bad Request` with body `{"error":"malformed payload"}`.

---

## Scenario 7 — Mailgun email bounce (different provider, different scheme)

Demonstrates that adding a new provider is one new class — Stripe and Mailgun share the
controller, the queue, the worker, the dedup table. Only the signature scheme and payload
shape differ.

```bash
cat > var/mailgun-bounce.json <<'JSON'
{
  "event-data": {
    "id": "Ase7i3vYTaaDP6yzaaTtmA",
    "event": "failed",
    "severity": "permanent",
    "recipient": "test@example.com",
    "reason": "Inbox full"
  }
}
JSON

SIG_MG=$(docker compose exec -T php php bin/sign-payload.php \
  --provider=mailgun \
  --payload-file=/var/www/html/var/mailgun-bounce.json \
  --secret=mailgun_test_secret_for_manual_testing)

curl -i -X POST http://127.0.0.1:8000/webhooks/mailgun \
  -H "Content-Type: application/json" \
  -H "X-Mailgun-Signature: $SIG_MG" \
  --data-binary @var/mailgun-bounce.json
```

**Expected:** `200 OK` with `{"status":"accepted","event_id":"Ase7i3vYTaaDP6yzaaTtmA"}`.

In Terminal B, the `EmailBouncedProcessor` logs the recipient. Confirm via
`docker compose exec php php bin/console app:webhooks:list --provider=mailgun`.

---

## Scenario 8 — Async failure + automatic retry

Simulates a downstream blowup (third-party API down, DB timeout, etc.). Messenger should
retry with exponential backoff (1s → 5s → 25s) and eventually move the message to the
failure transport — without losing the audit trail.

**8a. Make the processor fail.** Edit `src/EventProcessor/Stripe/PaymentSucceededProcessor.php`
and add `throw new \RuntimeException('simulated downstream outage');` as the first line of
`process()`. Save the file.

**8b. Restart the worker** (Terminal B): Ctrl+C, then `make worker` again. Restart is
necessary because PHP-FPM caches class definitions; `make worker` spins a fresh process so
your edit takes effect.

**8c. Send a brand-new event** (must be a unique `event_id` to bypass dedup):

```bash
cat > var/stripe-fail.json <<'JSON'
{"id":"evt_test_will_fail_001","type":"payment_intent.succeeded",
 "data":{"object":{"id":"pi_fail"}}}
JSON
SIG_F=$(docker compose exec -T php php bin/sign-payload.php \
  --provider=stripe \
  --payload-file=/var/www/html/var/stripe-fail.json \
  --secret=whsec_test_secret_for_manual_testing)
curl -s -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" -H "Stripe-Signature: $SIG_F" \
  --data-binary @var/stripe-fail.json
```

Watch Terminal B: 4 attempts (initial + 3 retries) with growing delays. Each writes
`status=FAILED, last_error="simulated downstream outage"` to the row. After the last retry
the message goes to the `failed` transport.

```bash
docker compose exec php php bin/console app:webhooks:list --status=failed
```

**8d. Recover.** Revert the throw in `PaymentSucceededProcessor.php`, then drain the
failure transport:

```bash
make drain
```

The message succeeds; the row flips to `COMPLETED`. **No data was lost** — the audit row
preserved the original payload through every retry.

---

## Scenario 9 — Inspecting state via the console

Operator-facing tooling. The full filter set:

```bash
# 20 most recent
docker compose exec php php bin/console app:webhooks:list

# Only failed events (triage view)
docker compose exec php php bin/console app:webhooks:list --status=failed

# Stripe events from the last hour
docker compose exec php php bin/console app:webhooks:list --provider=stripe --since="1 hour ago"

# Machine-readable for piping
docker compose exec php php bin/console app:webhooks:list --json | jq 'select(.status=="COMPLETED")'

# All filters together
docker compose exec php php bin/console app:webhooks:list \
  --provider=stripe --status=completed --since="24 hours ago" --limit=100
```

The `--json` mode emits one object per line so it composes naturally with `jq`, `grep`, or
log shippers.

---

## Cleanup

```bash
# Stop the worker (Ctrl+C in Terminal B)

# Stop the docker stack
make down

# Reset to a clean DB next time you bring it up
rm -f var/data_dev.db

# Remove the local secrets if you're done
rm .env.local
```
