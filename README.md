# FOSSBilling Service SaaS (`fossbilling-service-saas`)

Encrypted, multi-application SaaS provisioning module for [FOSSBilling](https://fossbilling.org) (`Box\Mod\Servicesaas`). Connects FOSSBilling products, orders, and invoices to one or more external application backends over an authenticated **Libsodium `XSalsa20-Poly1305` (`crypto_secretbox`)** JSON envelope channel.

## Features

- **End-to-End Encrypted Transport (`XSalsa20-Poly1305`)**: Mutual encryption for both outbound provisioning webhooks and inbound Guest API requests, with 32-byte per-app shared secrets (`hex`, `base64`, or raw), `±300s` clock-drift enforcement, and duplicate `request_id` replay protection.
- **Auto-Generated Encryption Keys & Pairing Export**: Automatically generates cryptographically random 64-char hex (`32-byte`) shared secrets on new app setup (or via Admin UI **Generate** / `servicesaas/generate_secret`) and provides one-click **Copy Pairing** JSON / `.env` snippets (`servicesaas/pairing_config`) with **zero unauthenticated key-exchange exposure**.
- **Automated Encrypted Catalog Sync (`sync_catalog`)**: External apps can push plans (Monthly `1M` & Annual `1Y` periods), add-ons (`once` or recurrent), and dynamic-amount items into FOSSBilling (`ProductCategory`, `Product`, `ProductPayment`) over the encrypted channel via `CatalogSyncInterface`. Legacy manual `saas` products are untouched and never archived.
- **Dynamic Amount Checkout (`dynamic_amount`)**: Supports variable amounts (e.g., wallet top-up `topup_saldo` and overage settlement `overage`) validated strictly inside the encrypted `checkout_session` envelope.
- **Multi-App Registry (`AppRegistry`)**: Register multiple independent apps (`app_id`, webhook URL, shared secret, redirect allowlists, `allow_catalog_sync` toggle) inside one FOSSBilling installation.
- **Storefront Pre-Purchase + Auto-Claim**: Customers can purchase on the FOSSBilling storefront before registering in your app (`pending_claim`). When they later sign up with the same email, your backend calls `claim_entitlement` to bind `external_user_id`, reset the subscription duration from the claim date, and mark the entitlement `provisioned`.
- **In-App Checkout Bridge (`checkout_session`)**: Supports `action=buy` and `action=update` on primary subscriptions while keeping standalone `addon`, `topup`, and `overage` orders cleanly isolated from subscription replacement. Dispatches `addon_activate` for add-ons and `invoice_paid` for top-up/overage orders.
- **Payment Gateway Agnostic & Portable Schema**: Works with any active FOSSBilling gateway (QRIS, Stripe, Tripay, Xendit, ClientBalance, etc.) and uses Doctrine DBAL (`service_saas`) across MySQL/MariaDB, PostgreSQL, and SQLite.

## Installation & Quick Pairing

1. Copy or clone this repository into your FOSSBilling modules directory:
   ```bash
   git clone https://github.com/Khuirul-Huda/fossbilling-service-saas.git src/modules/Servicesaas
   ```
2. Open **Admin -> Extensions -> Overview** in FOSSBilling and activate **SaaS & App Provisioning** (automatically creates the `service_saas` table).
3. Open **Settings -> SaaS & App Provisioning**:
   - Fill `app_id`, `base_url`, and `allowed_redirect_hosts`.
   - Leave **Shared Secret** blank to auto-generate a 64-char hex secret on save (or click **Generate**), and ensure **Allow encrypted automated catalog & pricing sync** is enabled.
   - Click **Copy Pairing** on the registered app row and paste it into your app's admin billing settings (or `.env`).
4. Optional backward-compatible manual mode: you can still create/manage products manually with type **Saas**, assigning matching `app_id`, `plan_id`, and metadata (`storage_gb`, `seats`, etc.) if your app does not call `sync_catalog`.

## Encrypted Envelope Contract (`v1`)

Both FOSSBilling and your external app backend wrap payloads inside:

```json
{
  "v": 1,
  "app_id": "cloudapp",
  "nonce": "<base64-encoded-24-byte-nonce>",
  "payload": "<base64-encoded-xsalsa20poly1305-ciphertext>"
}
```

Plaintext JSON payloads always include:
- `timestamp` (Unix epoch seconds, verified within `±300s`)
- `request_id` (unique random ID per request, cached against replay)

### Endpoints

| Direction | Endpoint | Purpose |
|---|---|---|
| FOSSBilling -> App | `POST <base_url><webhook_path>` | Provisioning & billing events: `activate`, `renew`, `suspend`, `unsuspend`, `cancel`, `addon_activate`, `invoice_paid` |
| App -> FOSSBilling | `POST /api/guest/servicesaas/sync_catalog` | Idempotently create/update `ProductCategory`, `Product`, and `ProductPayment` (`1M`, `1Y`, `once`, `dynamic_amount`) |
| App -> FOSSBilling | `POST /api/guest/servicesaas/checkout_session` | Create or upgrade order/invoice (fixed period or `dynamic_amount`) and return `/invoice/<hash>` |
| App -> FOSSBilling | `POST /api/guest/servicesaas/claim_entitlement` | Claim a `pending_claim` storefront pre-purchase on user signup |
| App -> FOSSBilling | `POST /api/guest/servicesaas/account_event` | Sync `email_changed` or `account_deleted` events |
| Admin API | `POST /api/admin/servicesaas/generate_secret` | Generate or rotate a 64-char hex shared secret |
| Admin API | `POST /api/admin/servicesaas/pairing_config` | Export copyable pairing JSON & `.env` snippet for an app |

- **Webhook Response Statuses from App**:
  - `provisioned` — quota/entitlement/top-up/add-on applied cleanly
  - `pending_registration` — email not registered yet (`pending_claim` stored; order stays `active`)
  - `downgraded` — paid entitlement suspended/canceled cleanly

## Running Tests

### Inside FOSSBilling (Unit, Feature, Integration & E2E)
```bash
./src/vendor/bin/pest --test-directory=modules/Servicesaas/tests --bootstrap=src/modules/Servicesaas/tests/bootstrap.php src/modules/Servicesaas/tests
./src/vendor/bin/phpstan analyse --no-progress --memory-limit=1G --level=5 src/modules/Servicesaas/src/
```

## License

Licensed under the [Apache License 2.0](LICENSE).
