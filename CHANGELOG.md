# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-10-23

### Added
- Initial release of WCManualPay plugin
- REST API endpoint `/wp-json/wcmanualpay/v1/notify` for transaction notifications
- Verify key authentication for REST API requests
- Database tables for transactions and audit logging
- Payment gateway extending WC_Payment_Gateway
- Checkout fields: payment provider dropdown and transaction ID input
- Automatic transaction matching with pending orders
- Amount validation (must match order total within 0.01 tolerance)
- Currency validation (must match order currency)
- 72-hour time window validation for transactions
- Transaction status management (pending/used)
- Idempotency support for duplicate transaction prevention
- Admin override functionality to manually complete payments
- Admin page for viewing transactions with filters
- Audit log page for tracking all actions and events
- HPOS (High-Performance Order Storage) compatibility
- WooCommerce Blocks support for block-based checkout
- Comprehensive security measures:
  - SQL injection protection via prepared statements
  - XSS protection via proper escaping
  - CSRF protection via nonces
  - Authentication logging
- Automatic order completion when transaction matches
- Order notes for payment verification events
- IP address and user agent logging in audit trail
- Support for multiple payment providers
- Pagination in admin pages
- Translation ready with text domain

### Security
- Verify key requirement for all API requests
- Failed authentication attempt logging
- Secure handling of sensitive transaction data
- Input sanitization and validation
- Output escaping to prevent XSS
- Prepared SQL statements to prevent injection

### Documentation
- Comprehensive README with features and usage
- API documentation with examples in multiple languages
- Testing guide with manual test cases
- Security testing guidelines
- Performance testing recommendations

## [Unreleased]

### Planned Features
- Webhook signature verification
- Custom status mappings
- Email notifications for matched transactions
- Export transactions to CSV
- Advanced filtering and search
- Transaction refund support
- Multi-currency conversion support
- Scheduled cleanup of old transactions
- REST API endpoints for querying transactions
- Dashboard widget with statistics
- WP-CLI commands
- Unit and integration tests
