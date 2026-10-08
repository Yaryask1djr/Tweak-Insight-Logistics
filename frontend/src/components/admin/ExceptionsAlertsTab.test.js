import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiPost } from '../../api/client';
import ExceptionsAlertsTab from './ExceptionsAlertsTab';

jest.mock('@tanstack/react-query', () => ({
    useQuery: jest.fn(), useMutation: jest.fn(), useQueryClient: jest.fn(),
}));
jest.mock('../../api/client', () => ({ apiGet: jest.fn(), apiPost: jest.fn() }));
jest.mock('../common/Toast', () => ({ showToast: { success: jest.fn(), error: jest.fn() } }));

test('cancels only before pickup and sends the endpoint contract explicitly', async () => {
    global.IS_REACT_ACT_ENVIRONMENT = true;
    const common = { request_time: '2020-01-01T00:00:00Z', delivery_person_name: 'Assigned driver', client_name: 'Client' };
    const deliveries = [
        { ...common, id: 1, status: 'assigned', pickup_time: null },
        { ...common, id: 2, status: 'in_transit', pickup_time: '2020-01-01T01:00:00Z' },
        { ...common, id: 3, status: 'failed', pickup_time: '2020-01-01T01:00:00Z' },
        { ...common, id: 4, status: 'cancelled', pickup_time: null },
    ];
    useQuery.mockImplementation(options => ({ data: options.queryKey[1] === 'exceptions-deliveries' ? { data: { items: deliveries, counts: { all: 4, delayed: 2, unassigned: 0, cancelled: 2 } } } : [] }));
    useMutation.mockImplementation(options => ({ isPending: false, mutate: values => options.mutationFn(values) }));
    useQueryClient.mockReturnValue({ invalidateQueries: jest.fn() });
    apiPost.mockResolvedValue({ data: { status: 'cancelled' } });
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    const buttons = label => [...container.querySelectorAll('button')].filter(button => button.textContent.trim() === label);
    try {
        // Direct React roots need act; no Testing Library render wrapper is used.
        // eslint-disable-next-line testing-library/no-unnecessary-act
        await act(async () => { root.render(<ExceptionsAlertsTab />); });
        expect(buttons('Cancel before pickup')).toHaveLength(1);
        expect(container.textContent).toContain('Parcel custody needs operations review.');
        await act(async () => { buttons('Cancel before pickup')[0].click(); });
        expect(buttons('Confirm cancellation')[0].disabled).toBe(true);
        const input = container.querySelector('input[aria-label="Cancellation reason"]');
        await act(async () => {
            Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, 'Client changed plans');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        await act(async () => { buttons('Confirm cancellation')[0].click(); });
        expect(apiPost).toHaveBeenCalledTimes(1);
        expect(apiPost).toHaveBeenCalledWith('/admin/resolve-delivery', {
            delivery_id: 1, status: 'cancelled', status_reason: 'Client changed plans',
        });
    } finally {
        await act(async () => { root.unmount(); });
        container.remove();
    }
});
