const mockAxios = {
    post: jest.fn(),
    create: jest.fn(),
    interceptors: { request: { use: jest.fn() }, response: { use: jest.fn() } },
};
jest.mock('axios', () => ({ __esModule: true, default: mockAxios }));

beforeEach(() => {
    mockAxios.post.mockReset();
    mockAxios.create.mockReturnValue({ interceptors: { request: { use: jest.fn() }, response: { use: jest.fn() } } });
    Object.defineProperty(navigator, 'locks', { configurable: true, value: undefined });
});

function tab() {
    let api;
    jest.isolateModules(() => { api = require('./client'); });
    return api;
}
const session = token => ({ data: { data: { access_token: token, user: { id: 1 } } } });

test('independent tab modules serialize refresh through the shared browser lock', async () => {
    let tail = Promise.resolve();
    const lock = jest.fn((name, work) => {
        const current = tail.then(work); tail = current.catch(() => {}); return current;
    });
    Object.defineProperty(navigator, 'locks', { configurable: true, value: { request: lock } });
    let active = 0; let peak = 0;
    mockAxios.post.mockImplementation(async () => {
        active++; peak = Math.max(peak, active);
        await Promise.resolve(); active--;
        return session(`token-${mockAxios.post.mock.calls.length}`);
    });
    const a = tab(); const b = tab();
    await Promise.all([a.refreshSession(), b.refreshSession(), a.refreshSession()]);
    expect(mockAxios.post).toHaveBeenCalledTimes(2);
    expect(peak).toBe(1);
    expect(lock).toHaveBeenCalledTimes(2);
    expect(a.getAccessToken()).toBeTruthy(); expect(b.getAccessToken()).toBeTruthy();
});

test('fallback retries a rotation conflict but never treats it as a successful session', async () => {
    mockAxios.post.mockRejectedValueOnce({ response: { status: 409 } }).mockResolvedValueOnce(session('winner-cookie'));
    const api = tab();
    expect((await api.refreshSession()).access_token).toBe('winner-cookie');
    expect(mockAxios.post).toHaveBeenCalledTimes(2);
});

test('authentication failure is not retried as a race', async () => {
    mockAxios.post.mockRejectedValue({ response: { status: 401 } });
    const api = tab();
    await expect(api.refreshSession()).rejects.toMatchObject({ response: { status: 401 } });
    expect(mockAxios.post).toHaveBeenCalledTimes(1);
    expect(api.getAccessToken()).toBeNull();
});
