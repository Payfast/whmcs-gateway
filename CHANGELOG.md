# Changelog

## [2.0.0](https://github.com/Payfast/whmcs-gateway/releases/tag/v2.0.0)

### Added

#### Branding

- Updated Common Library to v1.4.0.
- Replaced all branding with the new **Payfast by Network** logo.
- Rebranded the module configuration interface to **Payfast Gateway**.

#### Cron & PayBatch System Enhancements

- Introduced per-invoice tracking in the PayBatch system via new `sent_invoice` records in the `payhostpaybatch` table
- Added new environment variables:
    - `PAYBATCH_CRON_LOG` – dedicated logging for PayBatch cron operations.
    - `PAYBATCH_DEBUG_MODE` – enables verbose debugging output.
- Implemented comprehensive invoice-level fail-safes that prevent:
    - Duplicate PayBatch submissions.
    - Duplicate `AddInvoicePayment` calls.
    - Re-processing of already-paid invoices.
    - Overpayments caused by overlapping batches.
    - Duplicate transaction IDs (TXIDs).
- Added detailed log messages for every PayBatch state (success, skipped, failed, duplicate-prevention, etc.).

### Changed

- Reworked the PayBatch **PAY** hook to only include invoices that have not yet been submitted (now respects
  `sent_invoice` mapping).
- Updated the PayBatch **QUERY** hook to properly clean up both batch headers (`uploadid`) and invoice mappings (
  `sent_invoice`).
- Improved API request structure for easier debugging and traceability.
- Completely restructured the PayBatch configuration file (`paybatch_cron_config.php`) for clarity and maintainability.
- Significantly improved consistency and verbosity of logging across the entire module.

### Fixed

- Resolved double-deletion bug where `uploadid` and `sent_invoice` records were removed twice.
- Fixed edge cases where `handleLineItem()` was never triggered due to premature batch deletion.
- Eliminated race conditions when the PAY and QUERY hooks executed in close succession.
- Corrected processing errors when `TransResult` array was empty or zero-length.
- Restored and strengthened missing duplicate-payment protections.

## [[1.1.0]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.1.0)

### Fixed

- Fixed the paybatch_cron_config cron to meet WHMCS 8.13 security standards.

### Added

- Updated for PHP 8.1.

### Tested

- Compatibility with WHMCS 8.11.0 and PHP 8.1

## [[1.0.9]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.9)

### Changed

- Updated branding.
- Amended module file structure.

## [[1.0.8]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.8)

### Added

- Tested with WHMCS 8.6.1.
- Updated for PHP 8.0.

## [[1.0.7]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.7)

### Added

- Token support for returning customers in PayHost.
- Tested with WHMCS 8.5.1.

## [[1.0.6]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.6)

### Added

- Check for already paid invoices.
- Updated WSDL URL to correct syntax.

## [[1.0.5]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.5)

### Changed

- Used database rather than session for order information.
- Added standalone cron capability.
- Consolidated bug fixes.

## [[1.0.4]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.4)

### Added

- Auto currency convert feature for loaded currencies (PayBatch).

## [[1.0.3]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.3)

### Added

- Check for valid token format in callback.
- "Pay" button added to invoicing.

## [[1.0.2]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.2)

### Improved

- Better recurring handling using dedicated PayBatch cron scripts.

## [[1.0.1]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.1)

### Fixed

- Fixed case bug in ID.

### Added

- Redirect to PayBatch.
- Redirect to client invoices on failure.
- PayBatch notify.

## [[1.0.0]](https://github.com/Payfast/whmcs-gateway/releases/tag/v1.0.0)

### Added

- Initial release.
