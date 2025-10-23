# WCManualPay - WordPress WooCommerce Manual Payment Gateway

A comprehensive WooCommerce payment gateway plugin that enables manual payment verification via REST API with transaction management, audit logging, and admin override capabilities.

## Features

### Core Functionality
- **Manual Payment Gateway**: Accept payments with transaction IDs provided during checkout.
- **REST API Endpoint**: `/wp-json/wcmanualpay/v1/notify` authenticates webhook notifications with a shared secret.
- **Configurable Auto Verification**: Choose between Off, Lenient, and Strict matching strategies with configurable tolerances.
- **Optional Auto-completion**: Decide whether successfully matched transactions immediately complete the WooCommerce order.
- **Transaction Ledger**: Persist provider, transaction ID, amount, currency, payer (masked or clear), occurred_at, metadata, and immutable status.
- **Admin Tooling**: Link, mark used, reject, or unlink transactions directly from the WooCommerce dashboard with full audit trails.
- **Manual Overrides**: Complete payments from the order screen when necessary, enforcing a justification.

### Security & Validation
- **Idempotency Keys**: Deduplicate webhook deliveries with a provider/transaction hash.
- **IP Allowlist**: Restrict webhook access to trusted addresses or wildcards.
- **Amount & Currency Validation**: Enforce totals with mode-specific tolerances (0.01 for strict, ±5 for lenient).
- **Configurable Time Window**: Limit automatic matching to recent transactions by setting an hour-based window.
- **Audit Logging**: Capture every API, automation, and admin action with actor, object, and contextual JSON payloads.
- **Verify Key**: Require a shared secret for each webhook request.

### Compatibility
- **HPOS Compatible**: Full support for High-Performance Order Storage
- **WooCommerce Blocks**: Ready for WooCommerce Block-based checkout
- **Modern WordPress**: Built for WordPress 5.8+ and WooCommerce 6.0+

## Installation

1. Upload the plugin files to `/wp-content/plugins/wc-manual-pay/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments
4. Enable and configure the "Manual Pay" payment gateway
5. Set a secure verify key for REST API authentication
6. Configure payment providers (one per line)

## Configuration

### Payment Gateway Settings
- **Enable/Disable**: Toggle the payment gateway on/off
- **Title & Description**: Customer-facing copy shown during checkout
- **Payment Providers**: Accepted providers (newline separated)
- **Webhook Verify Key**: Shared secret required by the `/notify` endpoint
- **Auto-verify Mode**: Off, Lenient, or Strict order matching
- **Verification Window**: Maximum transaction age (in hours) considered for auto verification (0 disables)
- **Auto-complete on Match**: Automatically call `payment_complete()` when validation succeeds
- **Webhook IP Allowlist**: Comma/newline separated IPs or wildcard ranges permitted to hit the webhook
- **Mask Payer**: Store payer identifiers in masked form (*****1234) or as provided

## REST API

### Notify Endpoint

**URL**: `POST /wp-json/wcmanualpay/v1/notify`

**Authentication**: Include verify key in header or body
- Header: `X-Verify-Key: your_secret_key`
- Body: `verify_key: your_secret_key`
- Requests from IP addresses outside the configured allowlist (if provided) are rejected with `ip_not_allowed`.

**Request Body**:
```json
{
  "provider": "bkash",
  "txn_id": "ABC123456",
  "amount": 100.50,
  "currency": "BDT",
  "occurred_at": "2025-10-23 10:30:00",
  "status": "NEW",
  "payer": "+8801******45"
}
```

**Response**:
```json
{
  "success": true,
  "message": "Transaction created successfully.",
  "transaction_id": 123
}
```

**Required Fields**:
- `provider`: Payment provider name
- `txn_id`: Transaction ID from provider
- `amount`: Transaction amount (must be > 0)
- `currency`: Currency code (e.g., BDT, USD)

**Optional Fields**:
- `occurred_at`: Transaction timestamp (Y-m-d H:i:s format, defaults to current time)
- `status`: `NEW` (default) or `INVALID` to flag malformed payloads
- `payer`: Raw payer identifier (masked on storage when enabled)
- Any additional keys are stored in `meta_json` for later reconciliation

## How It Works

### Customer Checkout Flow
1. Customer selects "Manual Pay" as payment method
2. Customer enters payment provider and transaction ID
3. The plugin records the provider + transaction metadata on the order and attempts auto verification based on the configured mode
4. If auto verification validates the transaction:
   - The transaction is marked `USED`
   - The order transaction ID is set
   - Payment is completed immediately when auto-completion is enabled, or held in the `MATCHED` state awaiting manual approval
5. If no match is available (or auto verification is disabled):
   - The order is kept `on-hold`
   - Admins can reconcile later or await a webhook notification

### REST API Flow
1. Payment provider sends transaction notification to REST API
2. System validates verify key
3. Transaction stored in database with idempotency check
4. System attempts to match with on-hold/pending orders using the configured mode, tolerance, and verification window
5. If a match passes validation:
   - The transaction is marked `USED` (or `MATCHED` when auto-completion is disabled)
   - The order transaction ID is populated
   - Orders auto-complete when enabled; otherwise, they remain `on-hold` awaiting admin action
6. Every decision (match success, validation failure, rate limit, duplicate) is recorded in the audit log

### Admin Override
1. Admin views order details
2. If payment pending with transaction details
3. Admin can click "Complete Payment (Override)" button
4. Payment completed without transaction matching
5. Action logged in audit log

## Database Tables

### Transactions Table (`wp_wcmanualpay_transactions`)
Stores all payment transactions received via REST API:
- `id`: Unique transaction ID
- `provider`: Payment provider name
- `txn_id`: Transaction ID from provider
- `amount`: Transaction amount (decimal 18,6)
- `currency`: ISO-4217 currency code
- `payer`: Stored payer identifier (masked when enabled)
- `occurred_at`: When the transaction occurred
- `status`: `NEW`, `MATCHED`, `USED`, `INVALID`, or `REJECTED`
- `matched_order_id`: Linked WooCommerce order ID (nullable)
- `meta_json`: Raw payload snapshot from the webhook
- `idem_key`: SHA-256 hash of `provider|txn_id`
- `created_at` / `updated_at`: Audit timestamps maintained by MySQL

### Audit Log Table (`wp_wcmanualpay_audit`)
Logs every automated and manual action:
- `id`: Unique log entry ID
- `actor`: Originator (user email/login or `system:webhook`)
- `action`: High-level event code (e.g., `API_PAYMENT_MATCHED`, `ADMIN_OVERRIDE`)
- `object_type` / `object_id`: Target entity (order, transaction, webhook, etc.)
- `data_json`: Optional JSON metadata describing the event
- `at`: Timestamp captured at the moment of logging

## Admin Pages

### Transactions Page
Navigate to: **WooCommerce > Manual Pay**

**Features**:
- View all transactions with provider, amount, payer, occurred_at, status, and linked order
- Filter by provider or status (NEW, MATCHED, USED, INVALID, REJECTED)
- Link, mark used, reject, or unlink transactions directly from the list (with required reasons for destructive actions)
- View associated order links and audit logs
- Pagination support for large ledgers

**Tabs**:
- **Transactions**: List all payment transactions
- **Audit Log**: View all logged actions and events

## Validation Rules

### Transaction Matching
For a transaction to match an order:
1. Provider and transaction ID must match exactly
2. Transaction status must be `NEW` or `MATCHED` (never `USED`, `INVALID`, or `REJECTED`)
3. Amount difference must fall within the mode tolerance (≤0.01 for Strict, ≤5 currency units for Lenient)
4. Currency must match the WooCommerce order currency exactly
5. Transaction must fall within the configured verification window (unless the window is set to 0)

### Security
- All REST API requests require valid verify key
- Failed authentication attempts are logged
- SQL injection protection via prepared statements
- XSS protection via proper escaping
- CSRF protection via nonces for admin actions

## Development

### File Structure
```
wc-manual-pay/
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── blocks.js
├── includes/
│   ├── class-wcmanualpay-admin.php
│   ├── class-wcmanualpay-blocks.php
│   ├── class-wcmanualpay-blocks-integration.php
│   ├── class-wcmanualpay-database.php
│   ├── class-wcmanualpay-gateway.php
│   └── class-wcmanualpay-rest-api.php
└── wc-manual-pay.php
```

### Hooks Available

**Actions**:
- `wcmanualpay_transaction_created`: After transaction is created
- `wcmanualpay_transaction_used`: After transaction is marked as used
- `wcmanualpay_payment_completed`: After payment is completed

**Filters**:
- `wcmanualpay_providers`: Modify available providers list
- `wcmanualpay_validate_transaction`: Custom validation logic
- `wcmanualpay_72h_window`: Modify time window (in seconds)

## Requirements

- WordPress 5.8 or higher
- WooCommerce 6.0 or higher
- PHP 7.4 or higher

## Support

For issues, questions, or contributions, please visit:
https://github.com/md-riaz/wc-manual-pay

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Changelog

### 1.0.0
- Initial release
- REST API endpoint for transaction notifications
- Transaction management and matching
- Admin override functionality
- HPOS compatibility
- WooCommerce Blocks support
- Audit logging
- Idempotency support
- Amount/currency validation
- 72-hour window validation