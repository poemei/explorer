# STNC transport foundation

The Explorer reads Chain through STNC. Chain remains the authority for accepted
state. The transport library supplies validated INFO responses to the module's
status adapter, which connects the public/admin pages to configured Chain nodes.

## Current scope

`libraries/stnc_client.php` exposes only `getChainInfo()`, a read-only method-1
request. Its response contains the numeric Chain `status`, a hexadecimal
`request_id`, and the opaque binary `payload`. Successful INFO payloads are exactly
184 bytes; statuses 1 through 10 carry no payload. `explorer_status.php` decodes
INFO for display without native unsigned 64-bit conversion. Height and count use
exact decimal strings; target and the full 320-bit work value use hexadecimal.

Admin saves numeric primary/fallback IPs and ports in `explorer_settings` through
the module model. A valid INFO success is used from the primary, otherwise the
optional fallback is queried. No cross-node consistency or network matching is
inferred: operators must configure nodes on the intended network. The displayed
network/genesis IDs identify the responding node's Chain. No response is cached.

The existing Chain source (`includes/stn_rpc.h` and `src/stn_rpc.c`) controls the
wire layout: `STNC`, version **2**, request/response kind, method, status, 8-byte
request ID, and 4-byte payload length. The header is 24 bytes, with explicit
big-endian fields. Request IDs remain bytes rather than PHP integers. Current
Chain already defines INFO (1), block-by-height (2), and block-by-ID (3), in
addition to accepted-record and consumer-recovery methods (4–8). Those other
methods are not implemented in this client yet.

## Operator integration

```php
require_once '/path/to/explorer/libraries/stnc_client.php';
$client = new stnc_client($configuredIp, $configuredPort);
$response = $client->getChainInfo();
```

Use trusted server-side configuration for the numeric IPv4/IPv6 address and port.
Never accept an endpoint from a visitor. Numeric addresses avoid DNS resolution
outside the connection deadline. Defaults are a two-second connect timeout and
a five-second total request/response deadline. Each configurable timeout must be
finite, positive, and no more than 60 seconds. PHP 8.1 or later is required.

Each operation creates a fresh socket and closes it on success or failure.
Partial writes and fragmented reads share one monotonic operation deadline.
The response header is checked for version, kind, method, request association,
status and exact method-specific length before reading its body. INFO allocates
at most its 184-byte body; there is no automatic retry.

`stnc_exception::reason` distinguishes `CONNECTION_FAILED`, `TIMEOUT`,
`TRANSPORT_FAILED`, `INCOMPLETE_RESPONSE`, and `MALFORMED_RESPONSE` from valid
Chain statuses. Connection failure includes failure during connection setup;
`TIMEOUT` identifies expiration during the connected exchange. Offline or invalid
responses must never become a record-not-found result. Raw binary payloads must
be decoded by the model before normal escaped view rendering.

STNC here provides neither encryption nor authentication. Use the existing trusted
node/network deployment boundary. The library does not read STNS, query Stratum,
submit data, implement consensus, or change Chain. No live-node compatibility
qualification is claimed while Chain is paused.

## Local qualification

Run `php tests/stnc_client_test.php` from the module directory. The test launches
temporary loopback peers using PHP CLI and `proc_open`; it checks the request
bytes independently, fragmented responses, protocol errors, response association,
length bounds, truncation, timeouts, and successful operation after failures.
No Chain node, database or persistence files are needed.

The module manifest retains its existing release metadata. These development
changes have not been packaged or newly signed for distribution.
