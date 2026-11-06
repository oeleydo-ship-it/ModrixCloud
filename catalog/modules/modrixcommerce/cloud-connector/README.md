# Modrix Cloud Connector

Connects a store to **Modrix Cloud**, the official catalog of Modrix Commerce themes and extensions. It ships with the platform; enable it under Admin → Extensions.

## What it adds

- **Admin → Modrix Cloud**:
  - Every published theme and extension, with search and filters.
  - **Connect**: link the store to a Modrix Cloud account. Free and purchased packages then install in one click, and the account's licenses are added automatically; a license active on another store offers **Move here**.
  - **Add license key** for keys bought without an account.
  - Install and update with signature, size and checksum verification, and restore previous files from the install history.
- A daily `modrix:cloud:check` that refreshes license status and looks for updates. It never disables anything.

## Security

- **Connecting:** uses an authorization code with PKCE (S256). The code is valid for 5 minutes and works once.
- **Token:** the store keeps the connection token encrypted and never sends it to the browser.
- **Permissions:** connecting and installing need the `extensions.install` permission and a recent password confirmation.
- **Trusted keys:** the keys that verify packages are read only from `ECOMODULAR_MARKETPLACE_TRUSTED_KEYS`.
- **Address:** the **Modrix Cloud address** setting overrides `ECOMODULAR_MARKETPLACE_URL`.

## Data

The store's licenses and install history live in core tables (`marketplace_licenses`, `marketplace_update_history`). Disabling or uninstalling the connector keeps them, and the themes and extensions they cover keep working.

See `docs/marketplace/licensing.md` for the full licensing model.
