import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiPost } from '../../api/client';
import { showToast } from '../common/Toast';
import ActiveDeliveriesTab from './ActiveDeliveriesTab';

jest.mock('@tanstack/react-query', () => ({ useQuery: jest.fn(), useMutation: jest.fn(), useQueryClient: jest.fn() }));
jest.mock('../../api/client', () => ({ apiGet: jest.fn(), apiPost: jest.fn() }));
jest.mock('../common/Toast', () => ({ showToast: { success: jest.fn(), error: jest.fn(), info: jest.fn() } }));
jest.mock('../../utils/haptics', () => ({ triggerOtpSuccessHaptic: jest.fn(), triggerActionTapHaptic: jest.fn() }));

let container;
let root;
let refetch;
let invalidateQueries;
beforeEach(() => {
    jest.clearAllMocks();
    global.IS_REACT_ACT_ENVIRONMENT = true;
    container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container);
    refetch = jest.fn(); invalidateQueries = jest.fn();
    useQueryClient.mockReturnValue({ invalidateQueries });
    useMutation.mockImplementation(options => ({
        isPending: false,
        mutate: values => options.mutationFn(values).then(result => options.onSuccess?.(result, values)).catch(error => options.onError?.(error)),
    }));
    apiPost.mockResolvedValue({ data: { status: 'picked_up' } });
});
afterEach(async () => { await act(async () => root.unmount()); container.remove(); });
async function render(rows) {
    useQuery.mockReturnValue({ data: rows, refetch, isFetching: false });
    // Direct React roots require act; there is no Testing Library render wrapper.
    // eslint-disable-next-line testing-library/no-unnecessary-act
    await act(async () => root.render(<ActiveDeliveriesTab />));
}
const order = (payment_status, status = 'driver_en_route', id = 1) => ({
    id, payment_status, pickup_payment_verified: payment_status === 'paid', status, tracking_number: `TIL-TEST-${id}`, pickup_address: 'Kano pickup', delivery_address: 'Kano destination',
});
async function slide(input) {
    await act(async () => {
        Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, '100');
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

test('blocks collection for every non-paid state and provides a payment refresh action', async () => {
    await render(['unpaid', 'pending', 'refunded', 'waived', undefined].map((payment, i) => order(payment, 'driver_en_route', i + 1)));
    const sliders = [...container.querySelectorAll('input[type="range"]')];
    expect(sliders).toHaveLength(5);
    for (const input of sliders) { expect(input.disabled).toBe(true); await slide(input); }
    expect(apiPost).not.toHaveBeenCalled();
    expect(container.textContent).toContain('Do not collect the package');
    const refresh = [...container.querySelectorAll('button')].find(button => button.textContent === 'Refresh payment status');
    await act(async () => refresh.click());
    expect(refetch).toHaveBeenCalledTimes(1);
});

test('sends a pickup milestone without asserting payment or choosing its timestamp', async () => {
    await render([order('paid')]);
    const input = container.querySelector('input[type="range"]');
    expect(input.disabled).toBe(false);
    await slide(input);
    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(apiPost).toHaveBeenCalledWith('/delivery-person/update-status', { delivery_id: 1, status: 'picked_up' });
});

test('a legacy paid flag without verified evidence cannot enable collection', async () => {
    await render([{ ...order('paid'), pickup_payment_verified: undefined }]);
    expect(container.querySelector('input[type="range"]').disabled).toBe(true);
    expect(apiPost).not.toHaveBeenCalled();
});

test('shows the server rejection and refreshes stale payment/assignment data', async () => {
    const message = 'Fare payment must be recorded as paid before pickup.';
    apiPost.mockRejectedValue({ response: { status: 409, data: { message } } });
    await render([order('paid')]);
    await slide(container.querySelector('input[type="range"]'));
    expect(showToast.error).toHaveBeenCalledWith(message);
    expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['driver', 'active-assignments'] });
    expect(showToast.success).not.toHaveBeenCalled();
});

test('permits travel to pickup before payment and keeps existing custody actions available', async () => {
    await render([order('unpaid', 'assigned'), order('refunded', 'picked_up', 2)]);
    const buttons = [...container.querySelectorAll('button')];
    const route = buttons.find(button => button.textContent.includes('Start pickup route'));
    expect(route.disabled).toBe(false);
    await act(async () => route.click());
    expect(apiPost).toHaveBeenCalledWith('/delivery-person/update-status', { delivery_id: 1, status: 'driver_en_route' });
    expect(buttons.find(button => button.textContent.includes('Start GPS-tracked transit')).disabled).toBe(false);
});
