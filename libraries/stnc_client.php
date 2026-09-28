<?php
declare(strict_types=1);

/** Transport failures are distinct from a valid Chain status (including NOT_FOUND). */
final class stnc_exception extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

/** Read-only STNC v2 transport. Endpoint arguments must come from operator configuration. */
final class stnc_client
{
    private string $endpoint;

    public function __construct(
        string $address,
        int $port,
        private readonly float $connectTimeout = 0.5,
        private readonly float $operationTimeout = 1.0
    ) {
        if (filter_var($address, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535
            || !is_finite($connectTimeout) || $connectTimeout <= 0 || $connectTimeout > 60
            || !is_finite($operationTimeout) || $operationTimeout <= 0 || $operationTimeout > 60) {
            throw new InvalidArgumentException('Invalid STNC endpoint or timeout');
        }
        $host = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $this->endpoint = 'tcp://' . $host . ':' . $port;
    }

    /**
     * INFO is the initial transport probe. Its 184-byte body remains opaque here.
     * @return array{status:int, request_id:string, payload:string}
     */
    public function getChainInfo(): array
    {
        $id = random_bytes(8); // Preserve all 64 bits without native integer conversion.
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            $this->endpoint,
            $errno,
            $error,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            $detail = 'CONNECTION_FAILED';
            if ($errno !== 0 || $error !== '') {
                $detail .= ' (' . $errno . ($error !== '' ? ': ' . $error : '') . ')';
            }
            throw new stnc_exception($detail);
        }
        try {
            if (!stream_set_blocking($socket, false)) {
                throw new stnc_exception('TRANSPORT_FAILED');
            }
            $deadline = hrtime(true) / 1e9 + $this->operationTimeout;
            $request = 'STNC' . pack('nnnn', 2, 1, 1, 0) . $id . pack('N', 0);
            $offset = 0;
            while ($offset < strlen($request)) {
                $this->waitReady($socket, $deadline, true);
                $written = @fwrite($socket, substr($request, $offset));
                if ($written === false || ($written === 0 && feof($socket))) {
                    throw new stnc_exception('TRANSPORT_FAILED');
                }
                $offset += $written;
            }
            $header = $this->readExact($socket, 24, $deadline);
            $fields = unpack('nversion/nkind/nmethod/nstatus', substr($header, 4, 8));
            // Compare the encoded length directly; avoid unsigned u32 conversion on 32-bit PHP.
            $expectedLength = $fields['status'] === 0 ? 184 : 0;
            if (substr($header, 0, 4) !== 'STNC' || $fields['version'] !== 2
                || $fields['kind'] !== 2 || $fields['method'] !== 1
                || $fields['status'] > 10 || substr($header, 12, 8) !== $id
                || substr($header, 20, 4) !== pack('N', $expectedLength)) {
                throw new stnc_exception('MALFORMED_RESPONSE');
            }
            return ['status' => $fields['status'], 'request_id' => bin2hex($id),
                'payload' => $this->readExact($socket, $expectedLength, $deadline)];
        } finally {
            fclose($socket);
        }
    }

    private function readExact($socket, int $length, float $deadline): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->waitReady($socket, $deadline, false);
            $part = @fread($socket, $length - strlen($bytes));
            if ($part === false) {
                throw new stnc_exception('TRANSPORT_FAILED');
            }
            if ($part === '' && feof($socket)) {
                throw new stnc_exception('INCOMPLETE_RESPONSE');
            }
            $bytes .= $part;
        }
        return $bytes;
    }

    private function waitReady($socket, float $deadline, bool $writing): void
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new stnc_exception('TIMEOUT');
        }
        $seconds = (int) $remaining;
        $micros = (int) (($remaining - $seconds) * 1e6);
        $read = $writing ? [] : [$socket];
        $write = $writing ? [$socket] : [];
        $except = [];
        $ready = @stream_select($read, $write, $except, $seconds, $micros);
        if ($ready === false) {
            throw new stnc_exception('TRANSPORT_FAILED');
        }
        if ($ready === 0) {
            throw new stnc_exception('TIMEOUT');
        }
    }
}
