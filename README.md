# FOSSBilling Service SaaS (`fossbilling-service-saas`)

Encrypted, multi-application SaaS provisioning module for [FOSSBilling](https://fossbilling.org) (`Box\Mod\Servicesaas`). Connects FOSSBilling products, orders, and invoices to one or more external application backends over an authenticated **Libsodium `XSalsa20-Poly1305` (`crypto_secretbox`)** JSON envelope channel.

## Features

- **End-to-End Encrypted Transport (`XSalsa20-Poly1305`)**: Mutual encryption for both outbound provisioning webhooks and inbound Guest API requests, with 32-byte per-app shared secrets (`hex`, `base64`, or raw), `±300s` clock-drift enforcement, and duplicate `request_id` replay protection.
- **Multi-App Registry (`AppRegistry`)**: Register multiple independent apps (`app_id`, webhook URL, shared secret, redirect allowlists) inside one FOSSBilling installation without writing additional PHP modules.
- **Storefront Pre-Purchase + Auto-Claim**: Customers can purchase on the FOSSBilling storefront before registering in your app (`pending_claim`). When they later sign up with the same email, your backend calls `claim_entitlement` to bind `external_user_id`, reset the subscription duration from the claim date, and mark the entitlement `provisioned`.
- **In-App Checkout Bridge (`checkout_session`)**: Supports `action=buy` and `action=update` (reusing the active order to prevent duplicate subscriptions) and redirects back to allowlisted web URLs or app deep links after payment.
- **Payment Gateway Agnostic**: Routes payments through native FOSSBilling invoices, so any active payment gateway (Stripe, Tripay, Xendit, ClientBalance, etc.) works out of the box.
- **Portable Database Schema**: Uses Doctrine DBAL Schema API (`service_saas`), compatible with MySQL/MariaDB, PostgreSQL, and SQLite.

## Installation

1. Copy or clone this repository into your FOSSBilling modules directory:
   ```bash
   git clone https://github.com/Khuirul-Huda/fossbilling-service-saas.git src/modules/Servicesaas
   ```
2. Open **Admin -> Extensions -> Overview** in FOSSBilling and activate **SaaS & App Provisioning** (automatically creates the `service_saas` table).
3. Generate a 32-byte shared secret (64 hex chars or 32-byte base64):
   ```bash
   openssl rand -hex 32
   ```
4. Configure your app in **Settings -> SaaS & App Provisioning** (add `app_id`, `base_url`, `shared_secret`, `allowed_redirect_hosts`, and optional `allowed_custom_schemes`).
5. Create a product with type **Saas**, assign the matching `app_id`, `plan_id`, and optional quota metadata (`storage_gb`, `seats`, or custom JSON).

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
| FOSSBilling -> App | `POST <base_url><webhook_path>` | Provisioning commands: `activate`, `renew`, `suspend`, `unsuspend`, `cancel` |
| App -> FOSSBilling | `POST /api/guest/servicesaas/checkout_session` | Create or upgrade order/invoice and return `/invoice/<hash>` |
| App -> FOSSBilling | `POST /api/guest/servicesaas/claim_entitlement` | Claim a `pending_claim` storefront pre-purchase on user signup |
| App -> FOSSBilling | `POST /api/guest/servicesaas/account_event` | Sync `email_changed` or `account_deleted` events |

- **Webhook Response Statuses from App**:
  - `provisioned` — quota/entitlement active (`external_user_id` bound)
  - `pending_registration` — email not registered yet (`pending_claim` stored; order stays `active`)
  - `downgraded` — paid entitlement suspended/canceled cleanly

## Running Tests

### Standalone (Unit & Loopback Encrypted HTTP Integration)
```bash
composer install
vendor/bin/pest --configuration=phpunit.xml.dist --testsuite=Unit,Integration
```

### Inside FOSSBilling (Full 4-Layer Suite: Unit, Feature, Integration & E2E)
```bash
./src/vendor/bin/pest --test-directory=modules/Servicesaas/tests --bootstrap=src/modules/Servicesaas/tests/bootstrap.php src/modules/Servicesaas/tests
./src/vendor/bin/phpstan analyse --no-progress --memory-limit=1G --level=5 src/modules/Servicesaas/
```

## License

Licensed under the [Apache License 2.0](LICENSE).
