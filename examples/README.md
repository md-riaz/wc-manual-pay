# WCManualPay Examples

This directory contains example integrations and scripts for WCManualPay.

## Available Examples

### 1. bkash-webhook.php

Payment gateway webhook handler example for bKash integration.

**Usage**:
1. Copy to your WordPress installation
2. Configure your bKash webhook URL to point to this file
3. Update the signature verification logic based on bKash documentation
4. Set `bkash_webhook_secret` in WordPress options

**Features**:
- Webhook signature verification
- Automatic transaction creation in WCManualPay
- Error handling and logging
- Status validation

### 2. bulk-import.php

CLI script for bulk importing historical transactions.

**Usage**:
```bash
cd wp-content/plugins/wc-manual-pay/examples
php bulk-import.php
```

**Features**:
- Import multiple transactions at once
- Duplicate detection
- Automatic order matching
- Progress reporting
- Error handling

**Customization**:
Replace the `$transactions` array with your data source:
- Load from CSV file
- Query from external database
- Fetch from API

### 3. custom-integration.php

Advanced integration examples showing custom endpoints and hooks.

**Usage**:
1. Copy relevant code to your theme's `functions.php` or custom plugin
2. Modify as needed for your use case

**Features**:
- Custom REST API endpoints
- Additional validation logic
- Custom notifications
- Helper functions
- Transaction statistics

## Integration Scenarios

### Scenario 1: Payment Gateway Webhook

Use `bkash-webhook.php` as a template for any payment gateway that sends webhooks:

1. Receive webhook from payment gateway
2. Validate webhook signature
3. Extract transaction details
4. Send to WCManualPay API
5. Return response to payment gateway

### Scenario 2: Manual Data Migration

Use `bulk-import.php` to migrate historical payment data:

1. Prepare your transaction data
2. Format as array of transactions
3. Run the import script
4. Review import results
5. Check matched orders

### Scenario 3: Custom Business Logic

Use `custom-integration.php` to add custom features:

1. Create custom REST endpoints
2. Add additional validation
3. Implement custom notifications
4. Build reporting features
5. Integrate with other systems

## Best Practices

1. **Test First**: Always test integrations in a staging environment
2. **Validate Input**: Always validate and sanitize input data
3. **Log Events**: Use error_log() for debugging
4. **Handle Errors**: Implement proper error handling
5. **Secure Secrets**: Never commit API keys or secrets to version control
6. **Monitor**: Regularly check logs for issues
7. **Backup**: Ensure database backups before running imports

## Security Notes

- Always validate webhook signatures
- Use HTTPS for all API requests
- Keep verify keys and API keys secure
- Implement rate limiting for webhooks
- Sanitize all input data
- Escape all output data
- Use WordPress nonces for admin actions

## Need Help?

- Check the main [README.md](../README.md)
- Review [API.md](../API.md) for API details
- See [TESTING.md](../TESTING.md) for testing guide
- Read [QUICKSTART.md](../QUICKSTART.md) for quick setup

## Contributing

If you create useful integration examples, consider contributing them back to the project!
