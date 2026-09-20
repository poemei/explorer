<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/libraries/stnc_client.php';

// Independent loopback peer; no running Chain or persistence files required.
if (($argv[1] ?? '') === '--serve') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$server) { exit(2); }
    echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1) . "\n";
    flush();
    $peer = stream_socket_accept($server, 5);
    if (!$peer) { exit(3); }
    stream_set_timeout($peer, 2);
    $request = '';
    while (strlen($request) < 24) {
        $part = fread($peer, 24 - strlen($request));
        if ($part === false || $part === '') { exit(4); }
        $request .= $part;
    }
    if (substr($request, 0, 12) !== hex2bin('53544e430002000100010000')
        || substr($request, 20) !== str_repeat("\0", 4)) { exit(5); }
    $scenario = $argv[2];
    $status = $scenario === 'unavailable' ? 5 : ($scenario === 'not_found' ? 6 : 0);
    $body = $status === 0 ? str_repeat("\xa5", 184) : '';
    $header = hex2bin('53544e43000200020001') . pack('n', $status)
        . substr($request, 12, 8) . pack('N', strlen($body));
    switch ($scenario) {
        case 'magic': $header[0] = 'X'; break;
        case 'version': $header[5] = "\1"; break;
        case 'kind': $header[7] = "\1"; break;
        case 'method': $header[9] = "\2"; break;
        case 'id': $header[12] = chr(ord($header[12]) ^ 255); break;
        case 'status': $header[11] = "\x0b"; break;
        case 'oversize': $header = substr($header, 0, 20) . hex2bin('ffffffff'); $body = ''; break;
        case 'error_body': $header[11] = "\5"; break;
        case 'short_body': $body = substr($body, 0, 10); break;
        case 'short_header': $header = substr($header, 0, 10); $body = ''; break;
        case 'timeout': usleep(400000); $header = ''; $body = ''; break;
    }
    foreach (str_split($header . $body, 3) as $part) {
        if (@fwrite($peer, $part) === false) { break; }
        usleep(1000);
    }
    fclose($peer);
    fclose($server);
    exit(0);
}

$checks = 0;
$failures = 0;
$check = static function (bool $ok, string $name) use (&$checks, &$failures): void {
    ++$checks;
    if (!$ok) { ++$failures; echo "FAIL: $name\n"; }
};
$scenarios = ['valid' => null, 'unavailable' => null, 'not_found' => null,
    'magic' => 'MALFORMED_RESPONSE', 'version' => 'MALFORMED_RESPONSE',
    'kind' => 'MALFORMED_RESPONSE', 'method' => 'MALFORMED_RESPONSE',
    'id' => 'MALFORMED_RESPONSE', 'status' => 'MALFORMED_RESPONSE',
    'oversize' => 'MALFORMED_RESPONSE', 'error_body' => 'MALFORMED_RESPONSE',
    'short_body' => 'INCOMPLETE_RESPONSE', 'short_header' => 'INCOMPLETE_RESPONSE',
    'timeout' => 'TIMEOUT', 'valid_again' => null];
foreach ($scenarios as $scenario => $expected) {
    $process = proc_open([PHP_BINARY, __FILE__, '--serve', $scenario],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Fixture launch failed'); }
    try {
        stream_set_timeout($pipes[1], 5);
        $port = (int) fgets($pipes[1]);
        $client = new stnc_client('127.0.0.1', $port, 1, $scenario === 'timeout' ? 0.1 : 2);
        try {
            $result = $client->getChainInfo();
            $check($expected === null, "$scenario accepted");
            $status = $scenario === 'unavailable' ? 5 : ($scenario === 'not_found' ? 6 : 0);
            $check($result['status'] === $status, "$scenario status");
            $check($result['payload'] === ($status === 0 ? str_repeat("\xa5", 184) : ''), "$scenario body");
            $check(strlen($result['request_id']) === 16, "$scenario ID");
        } catch (stnc_exception $e) {
            $check($e->reason === $expected, "$scenario: {$e->reason}");
        }
        foreach ($pipes as $pipe) { fclose($pipe); }
        $check(proc_close($process) === 0, "$scenario fixture request validation");
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            proc_close($process);
        }
    }
}
foreach ([['example.com', 1, 1.0], ['127.0.0.1', 0, 1.0], ['127.0.0.1', 1, INF],
    ['127.0.0.1', 1, 0.0]] as [$host, $port, $timeout]) {
    try {
        new stnc_client($host, $port, $timeout);
        $check(false, 'invalid configuration accepted');
    } catch (InvalidArgumentException) { $check(true, 'invalid configuration rejected'); }
}
echo "$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
