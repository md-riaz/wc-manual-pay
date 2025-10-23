# API Reference - WCManualPay

## Overview

WCManualPay provides a REST API endpoint for receiving transaction notifications from payment providers. All requests must be authenticated using a verify key.

## Base URL

```
https://yoursite.com/wp-json/wcmanualpay/v1/
```

## Authentication

All API requests require authentication using a verify key. The verify key can be provided in two ways:

### Method 1: HTTP Header (Recommended)
```
X-Verify-Key: your_secret_key
```

### Method 2: Request Body
```json
{
  "verify_key": "your_secret_key",
  ...
}
```

## Endpoints

### POST /notify

Create a new transaction notification.

#### Request

**Headers**:
```
Content-Type: application/json
X-Verify-Key: your_secret_key
```

**Body**:
```json
{
  "provider": "bkash",
  "txn_id": "ABC123456",
  "amount": 150.75,
  "currency": "BDT",
  "occurred_at": "2025-10-23 10:30:00",
  "status": "pending"
}
```

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| provider | string | Yes | Payment provider name (e.g., bkash, nagad, rocket) |
| txn_id | string | Yes | Unique transaction ID from the provider |
| amount | decimal | Yes | Transaction amount (must be > 0) |
| currency | string | Yes | Currency code (e.g., BDT, USD) |
| occurred_at | string | No | Transaction timestamp in Y-m-d H:i:s format. Defaults to current time |
| status | string | No | Transaction status. Defaults to "pending" |

#### Response

**Success (201 Created)**:
```json
{
  "success": true,
  "message": "Transaction created successfully.",
  "transaction_id": 123
}
```

**Duplicate Transaction (200 OK)**:
```json
{
  "success": true,
  "message": "Transaction already exists.",
  "transaction_id": 123,
  "status": "pending"
}
```

**Error Responses**:

Invalid verify key (401):
```json
{
  "code": "invalid_verify_key",
  "message": "Invalid verify key.",
  "data": {
    "status": 401
  }
}
```

Missing verify key (401):
```json
{
  "code": "missing_verify_key",
  "message": "Verify key is required.",
  "data": {
    "status": 401
  }
}
```

Missing provider (400):
```json
{
  "code": "missing_provider",
  "message": "Provider is required.",
  "data": {
    "status": 400
  }
}
```

Missing transaction ID (400):
```json
{
  "code": "missing_txn_id",
  "message": "Transaction ID is required.",
  "data": {
    "status": 400
  }
}
```

Invalid amount (400):
```json
{
  "code": "invalid_amount",
  "message": "Valid amount is required.",
  "data": {
    "status": 400
  }
}
```

Missing currency (400):
```json
{
  "code": "missing_currency",
  "message": "Currency is required.",
  "data": {
    "status": 400
  }
}
```

Invalid occurred_at format (400):
```json
{
  "code": "invalid_occurred_at",
  "message": "Invalid occurred_at format. Use Y-m-d H:i:s",
  "data": {
    "status": 400
  }
}
```

## Examples

### cURL

**Basic request**:
```bash
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "Content-Type: application/json" \
  -H "X-Verify-Key: your_secret_key" \
  -d '{
    "provider": "bkash",
    "txn_id": "ABC123456",
    "amount": 150.75,
    "currency": "BDT"
  }'
```

**With all parameters**:
```bash
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "Content-Type: application/json" \
  -H "X-Verify-Key: your_secret_key" \
  -d '{
    "provider": "bkash",
    "txn_id": "ABC123456",
    "amount": 150.75,
    "currency": "BDT",
    "occurred_at": "2025-10-23 10:30:00",
    "status": "pending"
  }'
```

### PHP

**Using WordPress HTTP API**:
```php
<?php
$response = wp_remote_post('https://yoursite.com/wp-json/wcmanualpay/v1/notify', array(
    'headers' => array(
        'Content-Type' => 'application/json',
        'X-Verify-Key' => 'your_secret_key',
    ),
    'body' => json_encode(array(
        'provider' => 'bkash',
        'txn_id' => 'ABC123456',
        'amount' => 150.75,
        'currency' => 'BDT',
    )),
));

if (is_wp_error($response)) {
    // Handle error
    $error_message = $response->get_error_message();
} else {
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($body['success']) {
        $transaction_id = $body['transaction_id'];
        // Transaction created successfully
    }
}
```

**Using cURL**:
```php
<?php
$data = array(
    'provider' => 'bkash',
    'txn_id' => 'ABC123456',
    'amount' => 150.75,
    'currency' => 'BDT',
);

$ch = curl_init('https://yoursite.com/wp-json/wcmanualpay/v1/notify');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'X-Verify-Key: your_secret_key',
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
if ($result['success']) {
    echo "Transaction ID: " . $result['transaction_id'];
}
```

### Python

```python
import requests
import json

url = "https://yoursite.com/wp-json/wcmanualpay/v1/notify"
headers = {
    "Content-Type": "application/json",
    "X-Verify-Key": "your_secret_key"
}
data = {
    "provider": "bkash",
    "txn_id": "ABC123456",
    "amount": 150.75,
    "currency": "BDT"
}

response = requests.post(url, headers=headers, data=json.dumps(data))
result = response.json()

if result.get("success"):
    print(f"Transaction ID: {result['transaction_id']}")
else:
    print(f"Error: {result.get('message')}")
```

### JavaScript (Node.js)

```javascript
const axios = require('axios');

const url = 'https://yoursite.com/wp-json/wcmanualpay/v1/notify';
const headers = {
    'Content-Type': 'application/json',
    'X-Verify-Key': 'your_secret_key'
};
const data = {
    provider: 'bkash',
    txn_id: 'ABC123456',
    amount: 150.75,
    currency: 'BDT'
};

axios.post(url, data, { headers })
    .then(response => {
        if (response.data.success) {
            console.log('Transaction ID:', response.data.transaction_id);
        }
    })
    .catch(error => {
        console.error('Error:', error.response.data.message);
    });
```

## Transaction Matching

When a transaction is created via the API, WCManualPay automatically attempts to match it with pending orders. The matching criteria are:

1. **Provider Match**: Order must have the same provider
2. **Transaction ID Match**: Order must have the same transaction ID
3. **Amount Match**: Transaction amount must match order total (within 0.01 tolerance)
4. **Currency Match**: Transaction currency must match order currency
5. **Time Window**: Transaction must be within 72 hours of occurrence
6. **Status Check**: Transaction must not already be used

If all criteria are met, the order is automatically completed and the transaction is marked as "used".

## Idempotency

The API implements idempotency based on the combination of `provider` and `txn_id`. If you send the same transaction multiple times:

1. First request creates the transaction and returns 201 Created
2. Subsequent requests return 200 OK with the existing transaction details
3. No duplicate transactions are created

This ensures safe retry logic in case of network failures.

## Security Best Practices

1. **Keep Verify Key Secure**: Never commit the verify key to version control or expose it in client-side code
2. **Use HTTPS**: Always use HTTPS for API requests to encrypt the verify key in transit
3. **Rotate Keys**: Periodically rotate your verify key for enhanced security
4. **IP Whitelisting**: Consider implementing IP whitelisting at the server level for additional security
5. **Rate Limiting**: Implement rate limiting on the payment provider side to prevent abuse

## Rate Limits

Currently, there are no built-in rate limits. It's recommended to implement rate limiting at the web server level (e.g., using Nginx or Apache modules) or through a CDN/WAF service.

## Webhooks Integration Examples

### bKash Webhook Handler

```php
<?php
// Receive bKash IPN/webhook
$bkash_data = json_decode(file_get_contents('php://input'), true);

// Send to WCManualPay
$response = wp_remote_post('https://yoursite.com/wp-json/wcmanualpay/v1/notify', array(
    'headers' => array(
        'Content-Type' => 'application/json',
        'X-Verify-Key' => 'your_secret_key',
    ),
    'body' => json_encode(array(
        'provider' => 'bkash',
        'txn_id' => $bkash_data['trxID'],
        'amount' => $bkash_data['amount'],
        'currency' => 'BDT',
        'occurred_at' => date('Y-m-d H:i:s', strtotime($bkash_data['transactionTime'])),
        'status' => 'pending',
    )),
));
```

### Generic Payment Gateway Integration

```php
<?php
function send_transaction_to_wcmanualpay($provider, $txn_id, $amount, $currency, $occurred_at = null) {
    $data = array(
        'provider' => $provider,
        'txn_id' => $txn_id,
        'amount' => floatval($amount),
        'currency' => strtoupper($currency),
    );
    
    if ($occurred_at) {
        $data['occurred_at'] = $occurred_at;
    }
    
    $response = wp_remote_post('https://yoursite.com/wp-json/wcmanualpay/v1/notify', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-Verify-Key' => get_option('wcmanualpay_verify_key'),
        ),
        'body' => json_encode($data),
        'timeout' => 30,
    ));
    
    if (is_wp_error($response)) {
        error_log('WCManualPay API Error: ' . $response->get_error_message());
        return false;
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body;
}
```

## Troubleshooting

### Transaction Not Matching Order

Check that:
1. Provider name matches exactly (case-sensitive)
2. Transaction ID matches exactly
3. Amount matches order total (within 0.01)
4. Currency matches order currency
5. Transaction is within 72 hours
6. Transaction hasn't been used already

### Authentication Failures

Check that:
1. Verify key is correct
2. Verify key is included in header or body
3. No extra whitespace in verify key
4. REST API is enabled in WordPress

### Database Errors

Check that:
1. Database tables were created during activation
2. Database user has proper permissions
3. WordPress database prefix is correct

## Support

For API issues or questions, check the audit log at WooCommerce > Manual Pay > Audit Log to see detailed error information.
