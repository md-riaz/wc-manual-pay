# WCManualPay - Testing Guide

## Manual Testing Checklist

### 1. Plugin Installation and Activation

**Steps**:
1. Upload plugin to WordPress installation
2. Activate plugin via WordPress admin
3. Verify database tables are created:
   - `wp_wcmanualpay_transactions`
   - `wp_wcmanualpay_audit_log`

**Expected Results**:
- Plugin activates without errors
- Both database tables exist
- No PHP warnings or errors in debug log

### 2. Payment Gateway Configuration

**Steps**:
1. Navigate to WooCommerce > Settings > Payments
2. Find "Manual Pay" and click "Set up"
3. Enable the payment gateway
4. Set title: "Manual Payment"
5. Set description: "Pay using your transaction ID"
6. Set verify key: `test_key_12345`
7. Set providers:
   ```
   bkash
   nagad
   rocket
   ```
8. Save changes

**Expected Results**:
- Settings saved successfully
- Payment gateway appears in checkout

### 3. Customer Checkout Flow (Without Pre-existing Transaction)

**Steps**:
1. Add products to cart
2. Proceed to checkout
3. Select "Manual Pay" as payment method
4. Select provider: "bkash"
5. Enter transaction ID: "TXN001"
6. Place order

**Expected Results**:
- Order created with "Pending" status
- Order meta contains provider and txn_id
- Order note indicates awaiting verification
- Customer redirected to order received page

### 4. REST API - Create Transaction

**Test 1: Valid Transaction**

```bash
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "Content-Type: application/json" \
  -H "X-Verify-Key: test_key_12345" \
  -d '{
    "provider": "bkash",
    "txn_id": "TXN002",
    "amount": 100.50,
    "currency": "BDT",
    "occurred_at": "2025-10-23 10:30:00",
    "status": "pending"
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

**Test 2: Duplicate Transaction (Idempotency)**

Run the same request again.

**Expected Response**:
```json
{
  "success": true,
  "message": "Transaction already exists.",
  "transaction_id": 1,
  "status": "pending"
}
```

**Test 3: Invalid Verify Key**

```bash
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "Content-Type: application/json" \
  -H "X-Verify-Key: wrong_key" \
  -d '{
    "provider": "bkash",
    "txn_id": "TXN003",
    "amount": 100.50,
    "currency": "BDT"
  }'
```

**Expected Response**:
```json
{
  "code": "invalid_verify_key",
  "message": "Invalid verify key.",
  "data": {
    "status": 401
  }
}
```

**Test 4: Missing Required Fields**

```bash
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "Content-Type: application/json" \
  -H "X-Verify-Key: test_key_12345" \
  -d '{
    "provider": "bkash",
    "amount": 100.50
  }'
```

**Expected Response**:
```json
{
  "code": "missing_txn_id",
  "message": "Transaction ID is required.",
  "data": {
    "status": 400
  }
}
```

### 5. Customer Checkout with Matching Transaction

**Steps**:
1. Create transaction via REST API:
   ```bash
   curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
     -H "Content-Type: application/json" \
     -H "X-Verify-Key: test_key_12345" \
     -d '{
       "provider": "bkash",
       "txn_id": "TXN100",
       "amount": 50.00,
       "currency": "BDT"
     }'
   ```
2. Create order with cart total of 50.00 BDT
3. At checkout, select "Manual Pay"
4. Select provider: "bkash"
5. Enter transaction ID: "TXN100"
6. Place order

**Expected Results**:
- Order status immediately changes to "Processing" or "Completed"
- Order transaction ID set to "TXN100"
- Transaction in database marked as "used"
- Transaction `order_id` field set to order ID
- Transaction `used_at` timestamp set
- Audit log entries created
- Order note added with payment verification details

### 6. Validation Tests

**Test 1: Amount Mismatch**

1. Create transaction for 100.00 BDT
2. Create order with total 50.00 BDT
3. Try to use the transaction

**Expected Result**: Order remains pending with error note about amount mismatch

**Test 2: Currency Mismatch**

1. Create transaction with currency "USD"
2. Create order with currency "BDT"
3. Try to use the transaction

**Expected Result**: Order remains pending with error note about currency mismatch

**Test 3: Already Used Transaction**

1. Use transaction TXN100 for order #1
2. Try to use same transaction for order #2

**Expected Result**: Order #2 remains pending with error note that transaction already used

**Test 4: 72-Hour Window**

1. Create transaction with `occurred_at` set to 80 hours ago
2. Try to use the transaction

**Expected Result**: Order remains pending with error note about expired transaction

### 7. Admin Override

**Steps**:
1. Create order with pending payment
2. Navigate to WooCommerce > Orders
3. Open the pending order
4. Scroll to billing details section
5. Find "Manual Payment Override" box
6. Click "Complete Payment (Override)" button
7. Confirm action

**Expected Results**:
- Order status changes to "Processing" or "Completed"
- Order note added indicating admin override
- Audit log entry created for admin override
- Transaction ID set on order

### 8. Admin Transactions Page

**Steps**:
1. Navigate to WooCommerce > Manual Pay
2. View Transactions tab
3. Test filters:
   - Filter by status: "pending"
   - Filter by status: "used"
   - Filter by provider: "bkash"
4. View Audit Log tab
5. Check pagination

**Expected Results**:
- All transactions displayed correctly
- Filters work as expected
- Links to orders work
- Pagination works
- Audit log shows all actions

### 9. WooCommerce Blocks Checkout

**Prerequisites**: Install WooCommerce Blocks

**Steps**:
1. Create checkout page with WooCommerce Checkout Block
2. Add products to cart
3. Navigate to checkout
4. Select "Manual Pay" payment method
5. Fill in provider and transaction ID
6. Place order

**Expected Results**:
- Payment fields appear correctly in blocks checkout
- Form validation works
- Order processing works same as classic checkout

### 10. HPOS Compatibility

**Prerequisites**: Enable HPOS in WooCommerce

**Steps**:
1. Navigate to WooCommerce > Settings > Advanced > Features
2. Enable "High-Performance Order Storage"
3. Perform tests 3, 5, and 7 again

**Expected Results**:
- All functionality works with HPOS enabled
- No errors in debug log
- Orders stored in custom tables

## Security Testing

### 1. SQL Injection

Try injecting SQL in REST API:
```bash
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "X-Verify-Key: test_key_12345" \
  -d '{
    "provider": "bkash",
    "txn_id": "TXN\"; DROP TABLE wp_wcmanualpay_transactions; --",
    "amount": 100,
    "currency": "BDT"
  }'
```

**Expected**: Transaction stored safely, no SQL injection

### 2. XSS

Try injecting JavaScript:
```bash
curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
  -H "X-Verify-Key: test_key_12345" \
  -d '{
    "provider": "<script>alert(\"XSS\")</script>",
    "txn_id": "TXN200",
    "amount": 100,
    "currency": "BDT"
  }'
```

**Expected**: Script tags escaped and not executed in admin pages

### 3. CSRF Protection

Try accessing admin override without nonce:
```bash
curl -X POST https://yoursite.com/wp-admin/admin-ajax.php \
  -d 'action=wcmanualpay_admin_override&order_id=1'
```

**Expected**: Request rejected with nonce error

## Performance Testing

### Bulk Transaction Creation

Create 100 transactions:
```bash
for i in {1..100}; do
  curl -X POST https://yoursite.com/wp-json/wcmanualpay/v1/notify \
    -H "X-Verify-Key: test_key_12345" \
    -d "{
      \"provider\": \"bkash\",
      \"txn_id\": \"BULK$i\",
      \"amount\": 100,
      \"currency\": \"BDT\"
    }"
done
```

**Expected**: All transactions created successfully, admin page loads in reasonable time

## Error Scenarios

### 1. WooCommerce Not Active

1. Deactivate WooCommerce
2. Check plugin behavior

**Expected**: Admin notice shown indicating WooCommerce required

### 2. Database Connection Error

Simulate by modifying verify key to very long string (test DB handling)

**Expected**: Graceful error handling, no fatal errors

## Audit Log Verification

Check that these actions are logged:
- ✓ Transaction creation via API
- ✓ Payment completion
- ✓ Admin override
- ✓ Failed authentication attempts
- ✓ Validation failures
- ✓ Transaction matching

## Browser Compatibility

Test in:
- Chrome
- Firefox
- Safari
- Edge

**Focus on**:
- Checkout form display
- Admin pages layout
- JavaScript functionality
- Ajax requests

## Mobile Testing

Test checkout on:
- iOS Safari
- Android Chrome

**Check**:
- Form fields are usable
- Dropdowns work correctly
- Submit button accessible
