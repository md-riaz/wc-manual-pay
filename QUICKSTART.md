# WCManualPay - Quick Start Guide

## Installation Steps

### 1. Install the Plugin

**Option A: Direct Upload**
1. Download the plugin ZIP file
2. Go to WordPress Admin > Plugins > Add New
3. Click "Upload Plugin"
4. Choose the ZIP file and click "Install Now"
5. Click "Activate Plugin"

**Option B: Manual Installation**
1. Upload the `wc-manual-pay` folder to `/wp-content/plugins/`
2. Go to WordPress Admin > Plugins
3. Find "WCManualPay" and click "Activate"

### 2. Configure the Payment Gateway

1. Go to **WooCommerce > Settings > Payments**
2. Find "Manual Pay" in the list
3. Click **"Set up"** or **"Manage"**
4. Configure the following settings:

   **Enable/Disable**: ✓ Enable Manual Pay
   
   **Title**: `Manual Payment`
   
   **Description**: `Pay using your transaction ID from the payment provider`
   
   **Verify Key**: `your_secret_key_here_12345`
   _(Generate a strong, random key - this is critical for security!)_
   
   **Payment Providers**: 
   ```
   bkash
   nagad
   rocket
   upay
   ```

5. Click **"Save changes"**

### 3. Test the Payment Gateway

#### A. Test Customer Checkout (Without Pre-existing Transaction)

1. Add a product to your cart (e.g., price: 100 BDT)
2. Proceed to checkout
3. Fill in billing details
4. Select **"Manual Payment"** as payment method
5. You'll see two fields:
   - **Payment Provider**: Select "bkash"
   - **Transaction ID**: Enter "TEST001"
6. Place order
7. Order will be created with **"Pending"** status
8. You'll be redirected to the order received page

#### B. Create a Transaction via REST API

Open your terminal or API client (Postman, Insomnia, etc.) and run:

```bash
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "Content-Type: application/json" \
  -H "X-Verify-Key: your_secret_key_here_12345" \
  -d '{
    "provider": "bkash",
    "txn_id": "TEST002",
    "amount": 200.00,
    "currency": "BDT"
  }'
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Transaction created successfully.",
  "transaction_id": 1
}
```

#### C. Test Automatic Matching

1. First, create a transaction via API:
```bash
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "Content-Type: application/json" \
  -H "X-Verify-Key: your_secret_key_here_12345" \
  -d '{
    "provider": "nagad",
    "txn_id": "MATCH001",
    "amount": 150.00,
    "currency": "BDT"
  }'
```

2. Then, create an order:
   - Add products totaling 150 BDT to cart
   - Proceed to checkout
   - Select "Manual Payment"
   - Provider: "nagad"
   - Transaction ID: "MATCH001"
   - Place order

3. **Result**: Order should automatically change to **"Processing"** status!

### 4. View Transactions

1. Go to **WooCommerce > Manual Pay**
2. You'll see the **Transactions** tab with all transactions
3. Click **Audit Log** tab to see all actions

### 5. Test Admin Override

1. Create an order that remains in "Pending" status
2. Go to **WooCommerce > Orders**
3. Click on the pending order
4. Scroll to the billing details section
5. Find the **"Manual Payment Override"** section
6. Click **"Complete Payment (Override)"**
7. Confirm the action
8. Order status changes to "Processing"!

## Common Usage Scenarios

### Scenario 1: Payment Gateway Integration

If you have a payment gateway (bKash, Nagad, etc.) that sends webhooks:

**Create a webhook handler** (`payment-webhook.php`):

```php
<?php
// Load WordPress
require_once('../../../wp-load.php');

// Get webhook data
$webhook_data = json_decode(file_get_contents('php://input'), true);

// Validate webhook (your gateway's signature verification)
// ... your validation code ...

// Send to WCManualPay
$response = wp_remote_post(site_url('/wp-json/wcmanualpay/v1/notify'), array(
    'headers' => array(
        'Content-Type' => 'application/json',
        'X-Verify-Key' => get_option('wcmanualpay_verify_key'), // Store in WordPress options
    ),
    'body' => json_encode(array(
        'provider' => 'bkash', // or dynamically from webhook
        'txn_id' => $webhook_data['trxID'],
        'amount' => $webhook_data['amount'],
        'currency' => 'BDT',
        'occurred_at' => date('Y-m-d H:i:s', strtotime($webhook_data['paymentExecuteTime'])),
    )),
));

if (is_wp_error($response)) {
    // Log error
    error_log('WCManualPay Error: ' . $response->get_error_message());
} else {
    $body = json_decode(wp_remote_retrieve_body($response), true);
    // Log success
    error_log('Transaction created: ' . $body['transaction_id']);
}
```

### Scenario 2: Manual Transaction Entry

If you receive payments manually and want to record them:

**Use the REST API**:

```bash
# Record a manual payment
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "Content-Type: application/json" \
  -H "X-Verify-Key: your_secret_key_here_12345" \
  -d '{
    "provider": "bank_transfer",
    "txn_id": "BANK2025001",
    "amount": 5000.00,
    "currency": "BDT",
    "occurred_at": "2025-10-23 14:30:00"
  }'
```

### Scenario 3: Bulk Transaction Import

If you have historical transactions to import:

**Create a PHP script** (`import-transactions.php`):

```php
<?php
require_once('../../../wp-load.php');

$transactions = array(
    array('provider' => 'bkash', 'txn_id' => 'TXN001', 'amount' => 100, 'currency' => 'BDT'),
    array('provider' => 'nagad', 'txn_id' => 'TXN002', 'amount' => 200, 'currency' => 'BDT'),
    array('provider' => 'rocket', 'txn_id' => 'TXN003', 'amount' => 150, 'currency' => 'BDT'),
    // ... more transactions
);

foreach ($transactions as $txn) {
    $result = WCManualPay_Database::insert_transaction($txn);
    if ($result) {
        echo "Imported transaction {$txn['txn_id']}\n";
    } else {
        echo "Failed to import {$txn['txn_id']}\n";
    }
}
```

## Integration Examples

### PHP with WordPress

```php
<?php
function notify_wcmanualpay($provider, $txn_id, $amount, $currency) {
    $verify_key = 'your_secret_key_here_12345';
    
    $response = wp_remote_post(site_url('/wp-json/wcmanualpay/v1/notify'), array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-Verify-Key' => $verify_key,
        ),
        'body' => json_encode(array(
            'provider' => $provider,
            'txn_id' => $txn_id,
            'amount' => floatval($amount),
            'currency' => strtoupper($currency),
        )),
        'timeout' => 30,
    ));
    
    if (is_wp_error($response)) {
        return array('success' => false, 'error' => $response->get_error_message());
    }
    
    return json_decode(wp_remote_retrieve_body($response), true);
}

// Usage
$result = notify_wcmanualpay('bkash', 'ABC123', 100.50, 'BDT');
if ($result['success']) {
    echo "Transaction ID: " . $result['transaction_id'];
}
```

### Node.js

```javascript
const axios = require('axios');

async function notifyWCManualPay(provider, txnId, amount, currency) {
    try {
        const response = await axios.post(
            'https://yoursite.com/wp-json/wcmanualpay/v1/notify',
            {
                provider: provider,
                txn_id: txnId,
                amount: parseFloat(amount),
                currency: currency.toUpperCase()
            },
            {
                headers: {
                    'Content-Type': 'application/json',
                    'X-Verify-Key': 'your_secret_key_here_12345'
                }
            }
        );
        
        return response.data;
    } catch (error) {
        console.error('Error:', error.response?.data || error.message);
        return { success: false, error: error.message };
    }
}

// Usage
notifyWCManualPay('bkash', 'ABC123', 100.50, 'BDT')
    .then(result => {
        if (result.success) {
            console.log('Transaction ID:', result.transaction_id);
        }
    });
```

### Python

```python
import requests

def notify_wcmanualpay(provider, txn_id, amount, currency):
    url = 'https://yoursite.com/wp-json/wcmanualpay/v1/notify'
    headers = {
        'Content-Type': 'application/json',
        'X-Verify-Key': 'your_secret_key_here_12345'
    }
    data = {
        'provider': provider,
        'txn_id': txn_id,
        'amount': float(amount),
        'currency': currency.upper()
    }
    
    try:
        response = requests.post(url, headers=headers, json=data)
        response.raise_for_status()
        return response.json()
    except requests.exceptions.RequestException as e:
        print(f'Error: {e}')
        return {'success': False, 'error': str(e)}

# Usage
result = notify_wcmanualpay('bkash', 'ABC123', 100.50, 'BDT')
if result.get('success'):
    print(f"Transaction ID: {result['transaction_id']}")
```

## Troubleshooting

### Issue: Transaction Not Matching

**Check**:
1. Provider name matches exactly (case-sensitive)
2. Transaction ID matches exactly
3. Amount matches (within 0.01 difference)
4. Currency matches
5. Transaction is within 72 hours
6. Transaction hasn't been used already

**Debug**:
- Check **WooCommerce > Manual Pay > Transactions** tab
- Check **WooCommerce > Manual Pay > Audit Log** tab
- Check order notes on the order page

### Issue: REST API Returns 401

**Possible Causes**:
1. Verify key is incorrect
2. Verify key not included in request
3. Verify key has extra whitespace

**Solution**:
- Double-check the verify key in WooCommerce settings
- Ensure header is `X-Verify-Key` (case-sensitive)
- Remove any whitespace from the key

### Issue: Order Stays Pending

**Possible Causes**:
1. No transaction exists with matching details
2. Transaction amount/currency doesn't match
3. Transaction is older than 72 hours

**Solution**:
- Use admin override: Go to order page, click "Complete Payment (Override)"
- Check audit log for validation failure reasons

## Best Practices

1. **Secure Your Verify Key**: Use a strong, random key and keep it secret
2. **Use HTTPS**: Always use HTTPS for API requests
3. **Validate Webhooks**: If integrating with payment gateways, validate their webhook signatures
4. **Monitor Audit Log**: Regularly check the audit log for suspicious activity
5. **Test First**: Always test in a staging environment before production
6. **Backup Database**: Ensure regular backups of your WordPress database

## Next Steps

1. Configure your payment providers
2. Set up webhook endpoints for your payment gateways
3. Test the complete flow with real transactions
4. Train your staff on using the admin override
5. Monitor the transactions and audit log regularly

For more detailed information, see:
- [README.md](README.md) - Full documentation
- [API.md](API.md) - Complete API reference
- [TESTING.md](TESTING.md) - Testing guide
