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
    private const METHOD_INFO = 0x0001;
    private const METHOD_BLOCK_HEIGHT = 0x0002;
    private const METHOD_BLOCK_ID = 0x0003;
    private const METHOD_TRANSACTION_STATUS = 0x000d;
    private const MAX_BLOCK_BYTES = 1070465;
    private const BLOCK_OPERATION_TIMEOUT = 30.0;

    private string $endpoint;

    public function __construct(
        string $address,
        int $port,
        private readonly float $connectTimeout = 0.5,
        private readonly float $operationTimeout = 5.0
    ) {
        if (filter_var($address, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535
            || !is_finite($connectTimeout) || $connectTimeout <= 0 || $connectTimeout > 60
            || !is_finite($operationTimeout) || $operationTimeout <= 0 || $operationTimeout > 60) {
            throw new InvalidArgumentException('Invalid STNC endpoint or timeout');
        }
        $host = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $this->endpoint = 'tcp://' . $host . ':' . $port;
    }

    public function getChainInfo(): array { return $this->request(self::METHOD_INFO, '', 184); }

    public function getBlockByHeight(int|string $height): array
    {
        return $this->request(self::METHOD_BLOCK_HEIGHT, $this->encodeU64($height), null, self::BLOCK_OPERATION_TIMEOUT);
    }

    public function getBlockById(string $blockId): array
    {
        $blockId = strtolower(trim($blockId));
        if (preg_match('/^[0-9a-f]{64}$/', $blockId) !== 1) { throw new InvalidArgumentException('Invalid block ID'); }
        $payload = hex2bin($blockId);
        if (!is_string($payload) || strlen($payload) !== 32) { throw new InvalidArgumentException('Invalid block ID'); }
        return $this->request(self::METHOD_BLOCK_ID, $payload, null, self::BLOCK_OPERATION_TIMEOUT);
    }

    public function getTransactionStatus(string $transactionId): array
    {
        $transactionId = strtolower(trim($transactionId));
        if (preg_match('/^[0-9a-f]{64}$/', $transactionId) !== 1) { throw new InvalidArgumentException('Invalid transaction ID'); }
        $payload = hex2bin($transactionId);
        if (!is_string($payload) || strlen($payload) !== 32) { throw new InvalidArgumentException('Invalid transaction ID'); }
        return $this->request(self::METHOD_TRANSACTION_STATUS, $payload, 44);
    }

    private function request(int $method, string $payload, ?int $fixedSuccessLength, ?float $operationTimeout = null): array
    {
        $id = random_bytes(8);
        $errno = 0; $error = '';
        $socket = @stream_socket_client($this->endpoint, $errno, $error, $this->connectTimeout, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            $detail = 'CONNECTION_FAILED';
            if ($errno !== 0 || $error !== '') { $detail .= ' (' . $errno . ($error !== '' ? ': ' . $error : '') . ')'; }
            throw new stnc_exception($detail);
        }
        try {
            if (!stream_set_blocking($socket, false)) { throw new stnc_exception('TRANSPORT_FAILED'); }
            $timeout = $operationTimeout ?? $this->operationTimeout;
            $deadline = hrtime(true) / 1e9 + $timeout;
            $request = 'STNC' . pack('nnnn', 2, 1, $method, 0) . $id . pack('N', strlen($payload)) . $payload;
            $offset = 0;
            while ($offset < strlen($request)) {
                $this->waitReady($socket, $deadline, true);
                $written = @fwrite($socket, substr($request, $offset));
                if ($written === false || ($written === 0 && feof($socket))) { throw new stnc_exception('TRANSPORT_FAILED'); }
                $offset += $written;
            }
            $header = $this->readExact($socket, 24, $deadline);
            $fields = unpack('nversion/nkind/nmethod/nstatus', substr($header, 4, 8));
            if (!is_array($fields) || substr($header, 0, 4) !== 'STNC' || $fields['version'] !== 2 || $fields['kind'] !== 2 || $fields['method'] !== $method || $fields['status'] > 17 || substr($header, 12, 8) !== $id) {
                throw new stnc_exception('MALFORMED_RESPONSE');
            }
            $lengthFields = unpack('Nlength', substr($header, 20, 4));
            $length = is_array($lengthFields) ? (int) $lengthFields['length'] : -1;
            if ($length < 0 || $length > self::MAX_BLOCK_BYTES) { throw new stnc_exception('MALFORMED_RESPONSE'); }
            if ($fields['status'] !== 0 && $length !== 0) { throw new stnc_exception('MALFORMED_RESPONSE'); }
            if ($fields['status'] === 0 && $fixedSuccessLength !== null && $length !== $fixedSuccessLength) { throw new stnc_exception('MALFORMED_RESPONSE'); }
            if ($fields['status'] === 0 && $fixedSuccessLength === null && $length < 168) { throw new stnc_exception('MALFORMED_RESPONSE'); }
            return ['status' => $fields['status'], 'request_id' => bin2hex($id), 'payload' => $this->readExact($socket, $length, $deadline)];
        } finally { fclose($socket); }
    }

    private function encodeU64(int|string $value): string
    {
        $decimal = trim((string) $value);
        if ($decimal === '' || preg_match('/^[0-9]+$/', $decimal) !== 1) { throw new InvalidArgumentException('Invalid block height'); }
        $decimal = ltrim($decimal, '0'); if ($decimal === '') { $decimal = '0'; }
        $bytes = '';
        for ($i = 0; $i < 8; ++$i) { [$decimal, $remainder] = $this->decimalDivMod256($decimal); $bytes = chr($remainder) . $bytes; }
        if ($decimal !== '0') { throw new InvalidArgumentException('Block height exceeds u64'); }
        return $bytes;
    }

    private function decimalDivMod256(string $decimal): array
    {
        $quotient = ''; $remainder = 0;
        for ($i = 0, $length = strlen($decimal); $i < $length; ++$i) {
            $digit = ord($decimal[$i]) - 48; $number = $remainder * 10 + $digit; $q = intdiv($number, 256); $remainder = $number % 256;
            if ($quotient !== '' || $q !== 0) { $quotient .= (string) $q; }
        }
        return [$quotient === '' ? '0' : $quotient, $remainder];
    }

    private function readExact($socket, int $length, float $deadline): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $this->waitReady($socket, $deadline, false);
            $part = @fread($socket, $length - strlen($bytes));
            if ($part === false) { throw new stnc_exception('TRANSPORT_FAILED'); }
            if ($part === '' && feof($socket)) { throw new stnc_exception('INCOMPLETE_RESPONSE'); }
            $bytes .= $part;
        }
        return $bytes;
    }

    private function waitReady($socket, float $deadline, bool $writing): void
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) { throw new stnc_exception('TIMEOUT'); }
        $seconds = (int) $remaining; $micros = (int) (($remaining - $seconds) * 1e6);
        $read = $writing ? [] : [$socket]; $write = $writing ? [$socket] : []; $except = [];
        $ready = @stream_select($read, $write, $except, $seconds, $micros);
        if ($ready === false) { throw new stnc_exception('TRANSPORT_FAILED'); }
        if ($ready === 0) { throw new stnc_exception('TIMEOUT'); }
    }
}
