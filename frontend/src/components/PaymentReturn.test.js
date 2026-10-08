import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from './AuthContext';
import { apiPost } from '../api/client';
import PaymentReturn from './PaymentReturn';
jest.mock('@tanstack/react-query', () => ({ useQuery: jest.fn() }));
jest.mock('./AuthContext', () => ({ useAuth: jest.fn() }));
jest.mock('../api/client', () => ({ apiPost: jest.fn() }));
test('callback success claims are ignored and verification uses the owned server reference', async () => {
    global.IS_REACT_ACT_ENVIRONMENT = true;
    const container = document.createElement('div'); const root = createRoot(container);
    useAuth.mockReturnValue({ user: { id: 3, role: 'client' }, isRestoring: false });
    useQuery.mockReturnValue({ refetch: jest.fn() });
    const reference = 'TILPAY-' + 'a'.repeat(32);
    // eslint-disable-next-line testing-library/no-unnecessary-act
    await act(async () => root.render(<MemoryRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }} initialEntries={[`/payment/return?reference=${reference}&status=success&paid=true`]}><PaymentReturn /></MemoryRouter>));
    expect(container.textContent).not.toContain('Payment verified');
    const options = useQuery.mock.calls[0][0]; expect(options.enabled).toBe(true);
    await options.queryFn();
    expect(apiPost).toHaveBeenCalledWith('/deliveries/payments/verify', { reference });
    await act(async () => root.unmount());
});
