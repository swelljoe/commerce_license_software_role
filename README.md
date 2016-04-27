# commerce_license_software_role
Commerce License plugin for managing software licenses and roles

This is beta quality software. Don't rely on it, until you've tested thoroughly to insure it meets your needs.

# Dependencies

You'll need Drupal 7 with Commerce, Commerce License, and Commerce License Billing (for recurring subscriptions).

You'll also need the software_license module, which provides an entity type for software licenses (separate from the
Commerce License; this may be overkill for many people, but we had a requirement for managing an existing license table,
as we have other license-related software (non-Drupal) that interacts with it.
