"""Exercise the production Lua scripts with competing clients on disposable Redis.

Usage: QUEUE_TEST_REDIS_PORT=16379 python3 backend/tests/verify_redis_queue_concurrency.py
Only this run's randomly named queue keys are touched. Standard library only.
"""
import concurrent.futures
import json
import os
from pathlib import Path
import secrets
import socket


class Redis:
    def __init__(self, port):
        self.socket = socket.create_connection(('127.0.0.1', port), timeout=10)
        self.reader = self.socket.makefile('rb')

    def command(self, *args):
        values = [str(arg).encode() for arg in args]
        data = b'*%d\r\n' % len(values)
        data += b''.join(b'$%d\r\n' % len(value) + value + b'\r\n' for value in values)
        self.socket.sendall(data)
        return self.read()

    def read(self):
        line = self.reader.readline()
        if not line:
            raise RuntimeError('Redis connection closed')
        kind, value = line[:1], line[1:-2]
        if kind == b'-':
            raise RuntimeError(value.decode())
        if kind == b'+':
            return value.decode()
        if kind == b':':
            return int(value)
        length = int(value)
        if length == -1:
            return None
        if kind == b'*':
            return [self.read() for _ in range(length)]
        if kind != b'$':
            raise RuntimeError('Unexpected Redis response')
        data = self.reader.read(length)
        if self.reader.read(2) != b'\r\n':
            raise RuntimeError('Truncated Redis response')
        return data.decode()

    def close(self):
        self.reader.close()
        self.socket.close()


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def main():
    port = int(os.environ.get('QUEUE_TEST_REDIS_PORT', '0'))
    if not 1 <= port <= 65535:
        raise SystemExit('Set QUEUE_TEST_REDIS_PORT to an isolated Redis on 127.0.0.1.')
    queue = 'test_concurrent_' + secrets.token_hex(12)
    keys = [prefix + queue for prefix in ('queue:', 'queue:delayed:', 'queue:processing:', 'queue:failed:')]
    directory = Path(__file__).resolve().parents[1] / 'queue' / 'redis'
    common = (directory / 'common.lua').read_text()
    scripts = {name: common + '\n' + (directory / (name + '.lua')).read_text()
               for name in ('push', 'reserve', 'complete')}

    def evaluate(client, name, *args):
        return client.command('EVAL', scripts[name], 4, *keys, *args)

    client = Redis(port)
    try:
        count = 240
        for index in range(count):
            raw = json.dumps({'id': str(index), 'queue': queue, 'job_type': 'test.concurrent',
                              'payload': {}, 'attempts': 0, 'max_attempts': 3})
            evaluate(client, 'push', raw, 0)

        def reserve_batch():
            connection = Redis(port)
            jobs = []
            try:
                while True:
                    raw = evaluate(connection, 'reserve', queue, secrets.token_hex(24), 300, 100)
                    if not raw:
                        return jobs
                    jobs.append(raw)
            finally:
                connection.close()

        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            reserved = sum(list(pool.map(lambda _: reserve_batch(), range(8))), [])
        ids = [json.loads(raw)['id'] for raw in reserved]
        require(len(ids) == count and len(set(ids)) == count, 'Concurrent workers lost or duplicated jobs')
        require(client.command('ZCARD', keys[2]) == count, 'Reservations are not recoverable')

        # Reclaim every reservation, as if the entire worker pool had died.
        for raw in reserved:
            client.command('ZADD', keys[2], 0, raw)
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            recovered = sum(list(pool.map(lambda _: reserve_batch(), range(8))), [])
        recovered_jobs = [json.loads(raw) for raw in recovered]
        require(len(recovered_jobs) == count and {job['id'] for job in recovered_jobs} == set(ids),
                'Concurrent recovery lost or duplicated a job')
        require(all(job['attempts'] == 2 for job in recovered_jobs), 'Recovery lost attempt counts')
        for raw in reserved:
            require(evaluate(client, 'complete', raw) == 0, 'Stale worker acknowledged a reclaimed job')
        for raw in recovered:
            require(evaluate(client, 'complete', raw) == 1, 'Current worker could not complete its job')
        require(client.command('ZCARD', keys[2]) == 0, 'Completed work remains reserved')
        print(f'PASS: 8 competing clients reserved and recovered {count} jobs without loss or duplicate reservations; stale acknowledgements rejected.')
    finally:
        client.command('DEL', *keys)
        client.close()


if __name__ == '__main__':
    main()
