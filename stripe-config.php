<?php
/**
 * VibeStream - Stripe keys. Put this file in the VibeStream main folder.
 * 1. Make a free account at stripe.com and open Developers > API keys.
 * 2. Paste the "Secret key" below (it starts with sk_test_ while you are testing, sk_live_ when you go live).
 * 3. Optional but recommended once the site is online: Developers > Webhooks > add endpoint
 *    https://YOUR-SITE/stripe-webhook.php for the event "checkout.session.completed", and paste its signing secret (whsec_...).
 * NEVER share this file or upload it anywhere public. Never put the secret key in JavaScript.
 */
const VS_STRIPE_SECRET_KEY = '';      // e.g. 'sk_test_...'
const VS_STRIPE_WEBHOOK_SECRET = '';  // e.g. 'whsec_...'  (optional on localhost)
const VS_STRIPE_CURRENCY = 'usd';