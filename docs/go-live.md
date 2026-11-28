# Go-live checklist

What Colin must set up before Pegwatch takes money. The code for each item is
on `main` once the "Gemini review fixes" PR is merged. Tick each item in order.

## 1. Stripe in test mode (sandbox, now)

1. Create a Stripe sandbox account (test mode).
2. Create a product "Pegwatch Prospector (pre-sale)" with a one-off price of
   A$89.00, currency AUD. Copy the price ID (`price_...`).
3. In Developers > Webhooks, add the endpoint
   `https://pegwatch.com/hooks/stripe` and subscribe it to these 4 events:
   - `checkout.session.completed`
   - `checkout.session.async_payment_succeeded`
   - `checkout.session.expired`
   - `charge.refunded`
   Copy the signing secret (`whsec_...`).
4. In Laravel Cloud, set these environment variables, then redeploy:
   - `STRIPE_SECRET` = the test secret key (`sk_test_...`)
   - `STRIPE_PRESALE_PRICE` = the price ID
   - `STRIPE_WEBHOOK_SECRET` = the signing secret
5. Test the whole flow on pegwatch.com/pro with the card `4242 4242 4242 4242`:
   - The button reads "Pre-order Prospector" and goes to Stripe Checkout.
   - After payment you land on /pro with "Thank you, your pre-order is in".
   - The receipt email from Pegwatch arrives (and Stripe's own receipt).
   - In Stripe, refund the payment in full. The row's status becomes `refunded`.
   - Open a checkout and leave it. After 24 hours Stripe expires it and the row
     becomes `expired`.
   - Open `/pro?thanks=paid` with no `session_id`. It must say
     "We could not find that payment".
6. Delete the test rows before launch, including the review's test row
   (`pegwatch-review@example.com`).

## 2. Seller details (when the ABN arrives)

Set these in Laravel Cloud. Until they are set, the pages show no seller name.

- `PEGWATCH_BUSINESS_NAME`: the trading or legal name.
- `PEGWATCH_ABN`: the ABN.
- `PEGWATCH_GST_REGISTERED`: `true` only after you register for GST (required
  at A$75,000 turnover). While `false`, /pro, /terms and receipts say
  "No GST is charged". When `true`, they say the price includes GST; then
  make the Stripe price GST-inclusive and turn on tax invoices in Stripe.

## 3. Support mailbox

- `PEGWATCH_SUPPORT_EMAIL` defaults to `support@pegwatch.com`. Make that
  address receive mail (Cloudflare Email Routing to your inbox), or set the
  variable to an address you read. It is in the footer, /terms, /privacy, the
  /pro refund answer and the reply-to of receipts.
- Send a test message to it and confirm it arrives.

## 4. Legal pages

- Read /terms and /privacy. They are plain-language drafts, not legal advice.
  Change anything that is not true for your business.
- Confirm the refund promise: full refund for any reason until Prospector goes
  live, and a full refund to everyone if it never does.

## 5. Stripe live mode (after the ABN)

1. Activate the Stripe account (business details, ABN, bank account).
2. Create the same product and price in live mode, and the same webhook
   endpoint with the same 4 events.
3. Replace the three `STRIPE_*` variables with the live values. Redeploy.
4. Buy one place yourself with a real card, check the emails, then refund it.

## 6. Site settings

- `APP_URL=https://pegwatch.com` in Laravel Cloud. Production now forces every
  link and sitemap to this host and sends GET requests on any other host
  (such as `*.laravel.cloud`) there with a 301.
- After the deploy, check `https://pegwatch.com/sitemap.xml`: every `<loc>`
  must start with `https://pegwatch.com`. Resubmit it in Google Search Console.
- Make sure the deploy runs `php artisan migrate`: it adds
  `presales.stripe_payment_intent`.

## 7. Email delivery

- Confirm SPF, DKIM and DMARC for the sending domain in Resend.
- Send yourself each email once and check it reaches the inbox, not spam:
  register, sign-in link, watch confirmation, digest confirmation, pre-order
  receipt.

## Open items from the review that need you

- **Mining Registrar for South West fields (G17).** The department lists no
  Perth office address for the South West or Collie fields. Confirm which
  office handles them, then the tenement page can name it.
- **Linking a paid pre-order to an account.** A paid row is stored by email.
  When Prospector features ship, match `presales.email` to `users.email` to
  turn them on.
