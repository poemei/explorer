# STN Chain Explorer

Read-only STN Chain Explorer module for ChAoS MVC.

**Chain owns truth. STNC exposes it. ChAoS displays it.**

## Current development status

The initial module scaffold and STNC transport foundation are implemented.
The transport uses the existing STNC v2 INFO method to request Chain information,
with exact framing, request/response association, bounded deadlines, and socket
cleanup on success or failure.

The public page is currently a scaffold. It does not yet display live Chain data.
INFO payload decoding, page integration, record lookup and traversal, and consumer
reorganization handling remain future Explorer work. Chain development is paused;
live-node integration has not been qualified.

## Requirements

- ChAoS MVC for the module controller, model, views, and database lifecycle.
- PHP 8.1 or later with TCP stream support.
- A trusted, operator-configured Chain RPC IP address and port for live requests.
- PHP CLI with `proc_open` enabled for the standalone loopback tests.

The existing module admin scaffold manages its SQL schema. Its public route
requires that schema to be current, but the transport library and its tests do
not require a database or a running ChAoS installation.

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
| `models/` | Existing database lifecycle scaffold |
| `views/` | Public and admin presentation scaffold |
| `libraries/stnc_client.php` | Read-only STNC transport |
| `sql/` | Module schema and patch directory |
| `tests/stnc_client_test.php` | Independent loopback protocol fixtures |
| `module.json` | Module manifest and existing release metadata |
| `docs/` | Transport documentation and changelog |

## Validation

From the module directory:

```text
php tests/stnc_client_test.php
```

Latest local qualification: **46 checks, 0 failures**, with all six PHP files
passing syntax checks on Windows using PHP 8.5.9. Tests cover fragmented responses,
request validation, status handling, malformed headers, mismatched IDs, payload
bounds, truncation, timeouts, and successful requests after failures.

These are loopback fixture results, not live Chain integration qualification.
The development changes have not been packaged or newly signed for distribution.

See [CHANGELOG](docs/CHANGELOG.md) for development history.
