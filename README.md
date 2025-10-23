# WCManualPay - WordPress WooCommerce Manual Payment Gateway

A comprehensive WooCommerce payment gateway plugin that enables manual payment verification via REST API with transaction management, audit logging, and admin override capabilities.

## Features

### Core Functionality
- **Manual Payment Gateway**: Accept payments with manual transaction verification
- **REST API Endpoint**: `/wp-json/wcmanualpay/v1/notify` with verify key authentication
- **Transaction Management**: Store and track transactions with provider, txn_id, amount, currency, occurred_at, and status
- **Checkout Fields**: Provider selection dropdown and transaction ID input field
- **Automatic Matching**: Matches transactions with orders based on provider, txn_id, amount, and currency
- **Admin Override**: Allows admins to manually complete payments without transaction matching

### Security & Validation
- **Idempotency**: Prevents duplicate transaction entries
- **Amount & Currency Validation**: Ensures transaction matches order total and currency
- **72-Hour Window**: Validates transactions are within 72 hours of occurrence
- **Audit Log**: Comprehensive logging of all actions and events
- **Verify Key**: REST API authentication using secret verify key

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
- **Title**: Payment method title shown to customers
- **Description**: Payment method description shown during checkout
- **Verify Key**: Secret key for REST API authentication (keep secure!)
- **Payment Providers**: List of accepted providers (e.g., bkash, nagad, rocket)

## REST API

### Notify Endpoint

**URL**: `POST /wp-json/wcmanualpay/v1/notify`

**Authentication**: Include verify key in header or body
- Header: `X-Verify-Key: your_secret_key`
- Body: `verify_key: your_secret_key`

**Request Body**:
```json
{
  "provider": "bkash",
  "txn_id": "ABC123456",
  "amount": 100.50,
  "currency": "BDT",
  "occurred_at": "2025-10-23 10:30:00",
  "status": "pending"
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
- `status`: Transaction status (defaults to "pending")

## How It Works

### Customer Checkout Flow
1. Customer selects "Manual Pay" as payment method
2. Customer enters payment provider and transaction ID
3. System checks for matching transaction in database
4. If match found and valid:
   - Transaction marked as "used"
   - Order transaction ID set
   - Payment completed automatically
5. If no match:
   - Order set to "pending" status
   - Awaits transaction notification via REST API

### REST API Flow
1. Payment provider sends transaction notification to REST API
2. System validates verify key
3. Transaction stored in database with idempotency check
4. System attempts to match with pending orders
5. If match found and valid:
   - Transaction marked as "used"
   - Order payment completed
   - Customer and admin notified

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
- `amount`: Transaction amount
- `currency`: Currency code
- `occurred_at`: When transaction occurred
- `status`: Transaction status (pending/used)
- `order_id`: Associated WooCommerce order ID
- `used_at`: When transaction was used
- `created_at`: Record creation timestamp
- `updated_at`: Last update timestamp

### Audit Log Table (`wp_wcmanualpay_audit_log`)
Logs all actions and events:
- `id`: Unique log entry ID
- `action`: Action performed
- `transaction_id`: Related transaction ID
- `order_id`: Related order ID
- `user_id`: User who performed action
- `data`: Additional JSON data
- `ip_address`: IP address of request
- `user_agent`: User agent string
- `created_at`: Log entry timestamp

## Admin Pages

### Transactions Page
Navigate to: **WooCommerce > Manual Pay**

**Features**:
- View all transactions
- Filter by status (pending/used)
- Filter by provider
- View associated order links
- Pagination support

**Tabs**:
- **Transactions**: List all payment transactions
- **Audit Log**: View all logged actions and events

## Validation Rules

### Transaction Matching
For a transaction to match an order:
1. Provider and transaction ID must match exactly
2. Transaction status must be "pending" (not already used)
3. Amount must match order total (within 0.01 tolerance)
4. Currency must match order currency
5. Transaction must be within 72 hours of occurrence

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