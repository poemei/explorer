# STN Chain Explorer

Read-only STN Chain Explorer module for ChAoS MVC.

**Chain owns truth. STNC exposes it. ChAoS displays it.**

## Current development status

The initial module scaffold and STNC transport foundation are implemented.
The transport uses the existing STNC v2 INFO method to request Chain information,
with exact framing, request/response association, bounded deadlines, and socket
cleanup on success or failure.

The public page shows “Blockchain not configured” until setup is complete. Once
configured, it queries Chain and displays height, tip, network/genesis identifiers,
cumulative work, current target, block count, and local validation status. An
unreachable node produces an unavailable message rather than invented Chain data.
Record lookup, traversal, and consumer reorganization handling remain future work.
Chain development is paused; live-node integration has not been qualified.

## Requirements

- ChAoS MVC for the module controller, model, views, and database lifecycle.
- PHP 8.1 or later with TCP stream support.
- A trusted, operator-configured Chain RPC IP address and port for live requests.
- PHP CLI with `proc_open` enabled for the standalone loopback tests.

## Setup

1. Open `/admin/explorer` as an authorized administrator.
2. Select **Install SQL** for a fresh installation. For an existing 1.0.0
   installation, apply the signed 1.1.0 package through `/admin/modules`.
   Core executes and journals the declared exact-version migration, adding
   connection settings and advancing the schema marker. Explorer does not run
   update SQL itself.
3. Save the primary node's numeric IP address and STNC RPC port. Optionally supply
   a fallback node on the same intended Chain network. Leave both fallback fields
   empty to disable fallback. Website URLs and hostnames are not accepted.
4. Open `/explorer` to see the current Chain status.

Admin displays connection status after setup. A failed primary request tries the
configured fallback; use of fallback is visibly identified. Each page load queries
the node anew. With two configured nodes, the default timeouts allow roughly
14 seconds total in the worst case. Endpoint addresses are never shown publicly.

Missing or outdated SQL renders the public setup message without installing or
migrating anything. Admin mutations use POST and Core CSRF protection. **Delete
Data** clears operational records while preserving configuration and schema;
**Nuke** uses Core removal and includes the declared settings table. Reset/Recover
are not implemented because the module has no recoverable record lifecycle yet.

An incomplete schema requires administrator recovery; it is not treated as a
fresh installation. Copying development files over an installed 1.0.0 module is
not a substitute for Core's signed update process. This checkout has not been
packaged or newly signed, and no deployment migration is claimed.

## Transport usage

```php
require_once '/path/to/explorer/libraries/stnc_client.php';

$client = new stnc_client($configuredIp, $configuredPort);
try {
    $response = $client->getChainInfo();
    // Inspect the numeric Chain status before decoding the binary payload.
} catch (stnc_exception $error) {
    // Handle transport failure separately from a valid Chain response.
}
```

Endpoint values must come from trusted server-side configuration, never visitor
input. The client accepts numeric IPv4 or IPv6 addresses. Default timeouts are
two seconds for connecting and five seconds for the connected exchange.

Successful INFO responses contain an opaque 184-byte payload. The transport
does not yet translate it into display fields. STNC provides no encryption or
authentication here; deployment relies on the trusted node/network boundary.

The Explorer does not read Chain persistence, implement consensus, submit records,
or depend on Stratum. See [STNC integration notes](docs/STNC.md) for framing,
failure handling, supported scope, and deployment details.

## Layout

| Path | Purpose |
| --- | --- |
| `controllers/` | Public and admin module actions |
| `models/` | Database lifecycle and saved node configuration |
| `views/` | Public status and admin settings/lifecycle controls |
| `libraries/stnc_client.php` | Read-only STNC transport |
| `libraries/explorer_status.php` | INFO decoding and configured fallback |
| `sql/` | Module schema and patch directory |
| `tests/stnc_client_test.php` | Independent loopback protocol fixtures |
| `module.json` | Module manifest and existing release metadata |
| `docs/` | Transport documentation and changelog |

## Validation

From the module directory:

```text
php tests/stnc_client_test.php
php tests/explorer_test.php
```

Transport qualification: **46 checks, 0 failures**. Module qualification adds
**52 checks, 0 failures**, using Core/SQL doubles and a real loopback fallback
exchange. All eight PHP files pass syntax checks on Windows using PHP 8.5.9.
Tests cover configuration validation, lifecycle presentation, escaping, exact
integer decoding, fallback, fragmented responses,
request validation, status handling, malformed headers, mismatched IDs, payload
bounds, truncation, timeouts, and successful requests after failures.

These are fixture results, not deployed ChAoS/MySQL or live Chain qualification.
The development changes have not been packaged or newly signed for distribution.

See [CHANGELOG](docs/CHANGELOG.md) for development history.

## Development contract

Before further implementation, read `MODULE_CREATION_GUIDE.md`,
`CHAOS_MVC_MODULE_DATABASE_AGENT_HANDOFF.md`, applicable project instructions,
and this module's manifest. Use the ChAoS lifecycle, model, authorization and
CSRF contracts. Package migrations and Nuke remain Core-controlled; never call
private Core migration helpers from the module. Signed-package deployment and
disposable-database installation/migration/Nuke still require qualification.
