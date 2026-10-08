<?php

/** Minimal RESP2 client for isolated queue tests without a phpredis dependency. */
final class RedisQueueTestClient
{
    private $socket;

    public function __construct(int $port)
    {
        $this->socket = stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
        if ($this->socket === false) throw new RuntimeException("Test Redis unavailable: {$message}");
        stream_set_timeout($this->socket, 3);
    }

    public function command(string ...$arguments): mixed
    {
        $request = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) $request .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        while ($request !== '') {
            $written = fwrite($this->socket, $request);
            if (!$written) throw new RuntimeException('Test Redis write failed.');
            $request = substr($request, $written);
        }
        return $this->read();
    }

    public function eval(string $script, array $arguments, int $keyCount): mixed
    {
        return $this->command('EVAL', $script, (string)$keyCount, ...array_map('strval', $arguments));
    }

    public function close(): void
    {
        if (is_resource($this->socket)) fclose($this->socket);
    }

    public function ping(): mixed { return $this->command('PING'); }
    public function lLen(string $key): int { return $this->command('LLEN', $key); }
    public function zCard(string $key): int { return $this->command('ZCARD', $key); }
    public function setEx(string $key, int $ttl, string $value): bool { return $this->command('SETEX', $key, (string)$ttl, $value) === 'OK'; }
    public function get(string $key): mixed { return $this->command('GET', $key); }
    public function del(string $key): int { return $this->command('DEL', $key); }

    private function read(): mixed
    {
        $line = fgets($this->socket);
        if ($line === false) throw new RuntimeException('Test Redis response timed out or connection closed.');
        $kind = $line[0];
        $value = substr($line, 1, -2);
        if ($kind === '-') throw new RuntimeException($value);
        if ($kind === '+') return $value;
        if ($kind === ':') return (int)$value;
        $length = (int)$value;
        if ($length === -1) return null;
        if ($kind === '*') {
            $values = [];
            for ($i = 0; $i < $length; $i++) $values[] = $this->read();
            return $values;
        }
        if ($kind !== '$') throw new RuntimeException('Unexpected RESP response.');
        $data = '';
        while (strlen($data) < $length + 2) {
            $chunk = fread($this->socket, $length + 2 - strlen($data));
            if ($chunk === false || $chunk === '') throw new RuntimeException('Truncated RESP response.');
            $data .= $chunk;
        }
        return substr($data, 0, $length);
    }
}
