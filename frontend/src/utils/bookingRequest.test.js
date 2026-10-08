import { webcrypto } from 'crypto';
import { TextEncoder } from 'util';
import { bookingRequest } from './bookingRequest';

beforeAll(() => {
    Object.defineProperty(window, 'crypto', { value: webcrypto, configurable: true });
    global.TextEncoder = TextEncoder;
});
beforeEach(() => sessionStorage.clear());

test('lost-response retry and reordered data retain key without storing personal data', async () => {
    const first = await bookingRequest(1, { address: 'Private place', quantity: 2 });
    const retry = await bookingRequest(1, { quantity: 2, address: 'Private place' });
    expect(retry.key).toBe(first.key);
    expect(sessionStorage.getItem(sessionStorage.key(0))).toBe(first.key);
    expect(sessionStorage.key(0)).not.toContain('Private');
});
test('client identity and changed request data are scoped separately', async () => {
    const first = await bookingRequest(1, { quantity: 1 });
    expect((await bookingRequest(2, { quantity: 1 })).key).not.toBe(first.key);
    expect((await bookingRequest(1, { quantity: 2 })).key).not.toBe(first.key);
});
test('simultaneous preparation shares the key; confirmed new booking gets a new key', async () => {
    const [a, b] = await Promise.all([bookingRequest(1, { a: 1 }), bookingRequest(1, { a: 1 })]);
    expect(a.key).toBe(b.key);
    a.confirmed();
    expect((await bookingRequest(1, { a: 1 })).key).not.toBe(a.key);
});
