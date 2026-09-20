# Changelog

## Unreleased

### ChAoS lifecycle correction

- Removed module-local update SQL execution and its POST action. Pending updates
  direct administrators to Core's signed module updater.
- Declared the exact 1.0.0-to-1.1.0 migration in module.json and included the schema
  version marker update in that package migration.
- Incomplete schemas now require recovery rather than offering fresh installation.
- Explicit returns prevent rejected admin actions from continuing if Core's error
  renderer returns. Non-string action input is rejected without a PHP warning.
- Fresh Install SQL remains an explicit admin POST/CSRF operation under the module
  creation guide. Nuke remains Core-owned. No Core files were changed.
- Module qualification now passes 52 checks, including rejected-action flow,
  authorization/CSRF ordering, GET immutability, partial-schema protection, and
  migration/ownership declarations. All eight PHP files pass syntax checks.
  Signed-package execution and disposable-database Nuke are not yet qualified.

### Changed — 1.1.0

- Public Explorer renders an unconfigured message instead of the generic 503
  page when setup is incomplete, then shows decoded Chain INFO after setup.
- Added protected admin primary/fallback IP and port settings, connection status,
  validation, save feedback, and corrected placeholder admin form URLs.
- Added explicit 1.0.0-to-1.1.0 SQL migration and declared settings table ownership.
  Delete Data preserves connection settings; Core retains Nuke ownership.
- Added module tests for configuration, lifecycle states, escaping, exact INFO
  decoding, and actual loopback fallback: 36 checks, zero failures. Deployed
  MySQL/Core lifecycle and live Chain integration have not been qualified.

### Added

- Read-only STNC v2 PHP transport foundation using the existing Chain INFO method.
- Bounded connect/exchange deadlines, exact framing and request association,
  deterministic socket cleanup, and separate transport/protocol failure results.
- Independent loopback transport tests and operator integration documentation.
- Chain source, storage, consensus and Stratum remain unchanged; page integration
  and live-node qualification are not included in this increment.

## 1.0.0 — 2026-09-09

### Added

- Initial module implementation.
