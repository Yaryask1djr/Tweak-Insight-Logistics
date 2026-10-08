import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import NotificationInbox from './NotificationInbox';

jest.mock('../../api/client', () => ({ apiGet: jest.fn(), apiPost: jest.fn() }));
jest.mock('../AuthContext', () => ({ useAuth: () => ({ user: { id: 7 } }) }));
let root, container, client;
beforeEach(() => {
    jest.clearAllMocks(); global.IS_REACT_ACT_ENVIRONMENT = true;
    container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container);
    client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    apiGet.mockImplementation(async path => {
        if (path.includes('unread-count')) return { data: { unread_count: 41 } };
        const page = Number(new URL(path, 'https://example.test').searchParams.get('page'));
        return { data: [{ id: page, title: `Update ${page}`, body: 'Actual API body', notification_type: 'driver.offer', delivery_status: 'read', created_at: '2026-09-29 10:00:00' }], pagination: { current_page: page, per_page: 20, total_records: 60, total_pages: 3, has_next_page: page < 3, has_prev_page: page > 1 } };
    });
    apiPost.mockResolvedValue({});
});
afterEach(async () => { await act(async () => root.unmount()); client.clear(); container.remove(); });
async function flush() { await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); }); }
async function renderInbox() {
    // Direct React roots have no Testing Library wrapper.
    // eslint-disable-next-line testing-library/no-unnecessary-act
    await act(async () => root.render(<QueryClientProvider client={client}><NotificationInbox /></QueryClientProvider>));
    await flush();
}
test('renders canonical fields and account-wide unread count', async () => {
    await renderInbox();
    expect(container.textContent).toContain('Actual API body');
    expect(container.textContent).toContain('41 unread across your inbox');
    expect(container.textContent).toContain('Read');
    expect([...container.querySelectorAll('button')].some(button => button.textContent === 'Mark as read')).toBe(false);
});
test('requests the next server page and retains the envelope', async () => {
    await renderInbox();
    await act(async () => container.querySelector('button[aria-label="Next"]').click()); await flush();
    expect(apiGet.mock.calls.some(([path]) => path.includes('page=2'))).toBe(true);
    expect(container.textContent).toContain('Update 2');
    expect(container.querySelector('[aria-current="page"]').textContent).toBe('2');
});
test('unread filter is sent to the server', async () => {
    await renderInbox();
    const select = container.querySelector('select[aria-label="Notification status"]');
    await act(async () => { select.value = 'unread'; select.dispatchEvent(new Event('change', { bubbles: true })); }); await flush();
    expect(apiGet.mock.calls.some(([path]) => path.includes('unread=true'))).toBe(true);
});
