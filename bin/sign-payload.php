#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Dev-only helper for hand-crafting webhook signatures during manual testing.
 *
 * NOT for production use — providers sign requests on their own infrastructure; this exists
 * so a reviewer can paste a curl command and exercise the receiver without installing the
 * Stripe CLI or running against a live Mailgun account.
 *
 * Usage:
 *   php bin/sign-payload.php --provider=stripe   --payload-file=path.json --secret=whsec_xxx [--timestamp=N]
 *   php bin/sign-payload.php --provider=mailgun  --payload-file=path.json --secret=mg_xxx
 *
 * Output:
 *   Stripe  → t=<unix>,v1=<sha256-hex>           (paste into the Stripe-Signature header)
 *   Mailgun → <sha256-hex>                       (paste into the X-Mailgun-Signature header)
 */
$opts = getopt('', ['provider:', 'payload-file:', 'secret:', 'timestamp::', 'help']);

if (isset($opts['help']) || !isset($opts['provider'], $opts['payload-file'], $opts['secret'])) {
    fwrite(STDERR, <<<'TXT'
Usage:
  bin/sign-payload.php --provider=stripe|mailgun --payload-file=PATH --secret=SECRET [--timestamp=UNIX]

Required:
  --provider     stripe | mailgun
  --payload-file path to a file containing the EXACT raw body to be sent
  --secret       the signing secret matching WEBHOOK_<PROVIDER>_SECRET in your .env.local

Optional:
  --timestamp    unix timestamp to embed in the Stripe signature (defaults to now);
                 useful for testing replay-window rejection.

TXT);
    exit(1);
}

$provider = (string) $opts['provider'];
$file = (string) $opts['payload-file'];
$secret = (string) $opts['secret'];

if (!is_readable($file)) {
    fwrite(STDERR, sprintf("error: payload file not readable: %s\n", $file));
    exit(2);
}

$body = (string) file_get_contents($file);

switch ($provider) {
    case 'stripe':
        $timestamp = isset($opts['timestamp']) ? (int) $opts['timestamp'] : time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        echo sprintf("t=%d,v1=%s\n", $timestamp, $signature);
        break;

    case 'mailgun':
        echo hash_hmac('sha256', $body, $secret).PHP_EOL;
        break;

    default:
        fwrite(STDERR, sprintf("error: unknown provider %s (use stripe or mailgun)\n", $provider));
        exit(3);
}
