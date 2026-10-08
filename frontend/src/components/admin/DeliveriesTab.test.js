import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiGet } from '../../api/client';
import DeliveriesTab from './DeliveriesTab';

jest.mock('@tanstack/react-query', () => ({
    useQuery: jest.fn(), useMutation: jest.fn(), useQueryClient: jest.fn(),
}));
jest.mock('../../api/client', () => ({ apiGet: jest.fn(), apiPost: jest.fn() }));
jest.mock('../common/Toast', () => ({ showToast: { success: jest.fn(), error: jest.fn() } }));

test('renders eligible dispatch choices from the query selector array', async () => {
    global.IS_REACT_ACT_ENVIRONMENT = true;
    localStorage.clear();
    const drivers = [
        { user_id: 7, full_name: 'Eligible Rider', kyc_status: 'verified', active_status: 'active', account_status: 'active', is_approved: 1, availability_status: 'available' },
        { user_id: 8, full_name: 'Suspended Rider', kyc_status: 'verified', active_status: 'active', account_status: 'suspended', is_approved: 1, availability_status: 'available' },
    ];
    useQuery.mockImplementation(options => options.queryKey[1] === 'available-drivers'
        ? { data: options.select({ data: drivers }) } : { data: { data: [] } });
    useMutation.mockReturnValue({ isPending: false, mutate: jest.fn() });
    useQueryClient.mockReturnValue({ invalidateQueries: jest.fn() });
    apiGet.mockResolvedValue({ data: { deliveries: [{ id: 42, status: 'broadcasted', tracking_number: 'TIL-2026-TEST-ABCD' }], has_more: false } });
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    try {
        // React DOM's root.render does not wrap updates in act (unlike Testing Library).
        // eslint-disable-next-line testing-library/no-unnecessary-act
        await act(async () => { root.render(<DeliveriesTab />); });
        expect(container.textContent).toContain('Eligible Rider');
        expect(container.textContent).not.toContain('Suspended Rider');
        expect(container.textContent).toContain('TIL-2026-TEST-ABCD');
    } finally {
        await act(async () => { root.unmount(); });
        container.remove();
    }
});
