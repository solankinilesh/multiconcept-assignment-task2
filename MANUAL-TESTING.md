# Manual testing guide

A copy-paste tour of every behaviour the receiver implements. No need to run the test suite —
work through this file and you'll see all nine scenarios in roughly ten minutes.

## Prerequisites

- PHP 8.3+ (`php -v` to confirm)
- Composer 2.x
- SQLite (built into PHP via `pdo_sqlite`; nothing to install)
- `curl` and `openssl` (standard on macOS / Linux)
- No Docker, no MySQL, no Redis — the sample is self-contained on purpose.

## One-time setup

From the project root:

```bash
composer install
php bin/console doctrine:migrations:migrate -n
```

Override the signing secrets locally so the curl examples below match. **`.env.local` is
gitignored** — never commit real secrets.

```bash
cat > .env.local <<'EOF'
WEBHOOK_STRIPE_SECRET=whsec_test_secret_for_manual_testing
WEBHOOK_MAILGUN_SECRET=mailgun_test_secret_for_manual_testing
EOF
```

You'll need **two terminals**, both at the project root.

**Terminal A — HTTP server:**

```bash
symfony server:start
# or, if you don't have the Symfony CLI:
php -S 127.0.0.1:8000 -t public
```

**Terminal B — Messenger worker:**

```bash
php bin/console messenger:consume webhooks -vv
```

Keep both terminals visible. Curl responses appear in Terminal A's logs; async processor logs
appear in Terminal B.

> **Note on the helper:** every Stripe / Mailgun example below uses `bin/sign-payload.php`
> to compute a valid signature without needing the Stripe CLI. It mirrors each provider's
> spec exactly: HMAC-SHA256 of `{timestamp}.{body}` for Stripe, plain HMAC-SHA256 of the
> body for Mailgun.

---

## Scenario 1 — Successful Stripe webhook (happy path)

The everyday case: a real provider sending a real event with a valid signature. Demonstrates
fast accept + async hand-off.

```bash
cat > /tmp/stripe-payment.json <<'JSON'
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

SIG=$(php bin/sign-payload.php --provider=stripe \
  --payload-file=/tmp/stripe-payment.json \
  --secret=whsec_test_secret_for_manual_testing)

curl -i -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: $SIG" \
  --data-binary @/tmp/stripe-payment.json
```

**Expected:** `200 OK` with body

```json
{"status":"accepted","event_id":"evt_test_payment_001"}
```

In **Terminal B** (worker) you'll see a `stripe payment succeeded` log line within ~1s.

**Verify the persisted state:**

```bash
php bin/console app:webhooks:list
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
  --data-binary @/tmp/stripe-payment.json
```

**Expected:** `200 OK` with body

```json
{"status":"duplicate","event_id":"evt_test_payment_001"}
```

**No new line in Terminal B.** The processor wasn't called again — the unique constraint on
`(provider, external_event_id)` short-circuited the request before it ever reached the queue.

```bash
php bin/console app:webhooks:list
# → still ONE row for evt_test_payment_001, attempt_count=1
```

---

## Scenario 3 — Forged signature (security)

An attacker trying to inject a fraudulent payment by reusing a captured signature against
a swapped body. The signature is computed over the raw body, so any tamper invalidates it.

```bash
cat > /tmp/stripe-tampered.json <<'JSON'
{
  "id": "evt_test_payment_002",
  "type": "payment_intent.succeeded",
  "data": {"object": {"id": "pi_FORGED", "amount": 999999, "currency": "chf"}}
}
JSON

# Re-use $SIG from Scenario 1 — it was signed for /tmp/stripe-payment.json, not the tampered one
curl -i -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: $SIG" \
  --data-binary @/tmp/stripe-tampered.json
```

**Expected:** `401 Unauthorized` with body `{"error":"invalid signature"}`.

The attempt is recorded in the audit log even though no `processed_event` row was created
— security teams want to see repeated invalid signatures from the same IP.

```bash
php bin/console dbal:run-sql \
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
SIG_OLD=$(php bin/sign-payload.php --provider=stripe \
  --payload-file=/tmp/stripe-payment.json \
  --secret=whsec_test_secret_for_manual_testing \
  --timestamp=$(($(date +%s) - 600)))

curl -i -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: $SIG_OLD" \
  --data-binary @/tmp/stripe-payment.json
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
# Need a fresh signature for this body so we get past signature verification first
echo -n 'not even close to json' > /tmp/garbage
SIG_GARBAGE=$(php bin/sign-payload.php --provider=stripe \
  --payload-file=/tmp/garbage \
  --secret=whsec_test_secret_for_manual_testing)

curl -i -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: $SIG_GARBAGE" \
  --data-binary @/tmp/garbage
```

**Expected:** `400 Bad Request` with body `{"error":"malformed payload"}`.

---

## Scenario 7 — Mailgun email bounce (different provider, different scheme)

Demonstrates that adding a new provider is one new class — Stripe and Mailgun share the
controller, the queue, the worker, the dedup table. Only the signature scheme and payload
shape differ.

```bash
cat > /tmp/mailgun-bounce.json <<'JSON'
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

SIG_MG=$(php bin/sign-payload.php --provider=mailgun \
  --payload-file=/tmp/mailgun-bounce.json \
  --secret=mailgun_test_secret_for_manual_testing)

curl -i -X POST http://127.0.0.1:8000/webhooks/mailgun \
  -H "Content-Type: application/json" \
  -H "X-Mailgun-Signature: $SIG_MG" \
  --data-binary @/tmp/mailgun-bounce.json
```

**Expected:** `200 OK` with `{"status":"accepted","event_id":"Ase7i3vYTaaDP6yzaaTtmA"}`.

In Terminal B, the `EmailBouncedProcessor` logs the recipient. Confirm via
`php bin/console app:webhooks:list --provider=mailgun`.

---

## Scenario 8 — Async failure + automatic retry

Simulates a downstream blowup (third-party API down, DB timeout, etc.). Messenger should
retry with exponential backoff (1s → 5s → 25s) and eventually move the message to the
failure transport — without losing the audit trail.

**8a. Make the processor fail.** Edit `src/EventProcessor/Stripe/PaymentSucceededProcessor.php`
and add `throw new \RuntimeException('simulated downstream outage');` as the first line of
`process()`. Save the file.

**8b. Restart the worker** (Terminal B): Ctrl+C, then `php bin/console messenger:consume webhooks -vv` again.

**8c. Send a brand-new event** (must be a unique `event_id` to bypass dedup):

```bash
cat > /tmp/stripe-fail.json <<'JSON'
{"id":"evt_test_will_fail_001","type":"payment_intent.succeeded",
 "data":{"object":{"id":"pi_fail"}}}
JSON
SIG_F=$(php bin/sign-payload.php --provider=stripe \
  --payload-file=/tmp/stripe-fail.json \
  --secret=whsec_test_secret_for_manual_testing)
curl -s -X POST http://127.0.0.1:8000/webhooks/stripe \
  -H "Content-Type: application/json" -H "Stripe-Signature: $SIG_F" \
  --data-binary @/tmp/stripe-fail.json
```

Watch Terminal B: 4 attempts (initial + 3 retries) with growing delays. Each writes
`status=FAILED, last_error="simulated downstream outage"` to the row. After the last retry
the message goes to the `failed` transport.

```bash
php bin/console app:webhooks:list --status=failed
```

**8d. Recover.** Revert the throw in `PaymentSucceededProcessor.php`, then drain the failure
transport:

```bash
php bin/console messenger:consume failed -vv --limit=1
```

The message succeeds; the row flips to `COMPLETED`. **No data was lost** — the audit row
preserved the original payload through every retry.

---

## Scenario 9 — Inspecting state via the console

Operator-facing tooling. The full filter set:

```bash
# 20 most recent
php bin/console app:webhooks:list

# Only failed events (triage view)
php bin/console app:webhooks:list --status=failed

# Stripe events from the last hour
php bin/console app:webhooks:list --provider=stripe --since="1 hour ago"

# Machine-readable for piping
php bin/console app:webhooks:list --json | jq 'select(.status=="COMPLETED")'

# All filters together
php bin/console app:webhooks:list --provider=stripe --status=completed --since="24 hours ago" --limit=100
```

The `--json` mode emits one object per line so it composes naturally with `jq`, `grep`, or
log shippers.

---

## Cleanup

```bash
# Stop the worker (Ctrl+C in Terminal B)
# Stop the server (Ctrl+C in Terminal A, or `symfony server:stop`)

# Reset to a clean DB if you want to start over
rm -f var/data_dev.db
php bin/console doctrine:migrations:migrate -n
```

Remove the local secrets if you're done:

```bash
rm .env.local
```
