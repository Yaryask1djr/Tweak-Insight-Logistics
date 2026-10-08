import React, { act } from 'react';
import { createRoot } from 'react-dom/client';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { apiPost } from '../../api/client';
import PaymentReviewPanel, { PaymentEvidence, safeCheckoutUrl } from './PaymentReviewPanel';

jest.mock('@tanstack/react-query', () => ({ useQuery: jest.fn(), useQueryClient: jest.fn() }));
jest.mock('../../api/client', () => ({ apiGet: jest.fn(), apiPost: jest.fn() }));
let container, root;
const payment = { fare: { amount_minor: 65025, version: 2, reason: 'Route checked' }, fare_current: true, can_pay: true, can_approve: true, payments_available: true };
beforeEach(() => {
    jest.clearAllMocks(); global.IS_REACT_ACT_ENVIRONMENT = true;
    container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container);
    useQueryClient.mockReturnValue({ invalidateQueries: jest.fn() });
    useQuery.mockReturnValue({ data: { data: payment }, refetch: jest.fn() }); apiPost.mockResolvedValue({});
});
afterEach(async () => { await act(async () => root.unmount()); container.remove(); });
async function render(element) {
    // Direct React roots have no Testing Library wrapper.
    // eslint-disable-next-line testing-library/no-unnecessary-act
    await act(async () => root.render(element));
}
async function enter(selector, value) {
    const input = container.querySelector(selector);
    await act(async () => {
        const prototype = input.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype;
        Object.getOwnPropertyDescriptor(prototype, 'value').set.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
}
test('approves a decimal fare against the reviewed version', async () => {
    await render(<PaymentReviewPanel deliveryId={7} admin />);
    await enter('input', '701.05'); await enter('textarea', 'Updated route');
    await act(async () => container.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    expect(apiPost).toHaveBeenCalledWith('/admin/deliveries/approve-fare', { delivery_id: 7, expected_version: 2, amount: '701.05', reason: 'Updated route' });
});
test('customer accepts a fare version without sending an amount or paid flag', async () => {
    await render(<PaymentReviewPanel deliveryId={7} />);
    const button = [...container.querySelectorAll('button')].find(b => b.textContent.startsWith('Accept fare'));
    await act(async () => button.click());
    expect(apiPost).toHaveBeenCalledWith('/deliveries/payments/initialize', { delivery_id: 7, fare_version: 2 });
    expect(container.textContent).toContain('Payment has not been verified');
});
test('a recorded flag cannot produce a verified receipt message', async () => {
    await render(<PaymentEvidence payment={{ payment_status: 'paid' }} />);
    expect(container.textContent).toContain('Pickup remains blocked');
});
test('a dispute hold takes precedence over a recorded receipt', async () => {
    await render(<PaymentEvidence payment={{ attempt: { status: 'review_required' }, receipt: { amount_minor: 65025 } }} />);
    expect(container.textContent).toContain('Do not pay again');
    expect(container.textContent).not.toContain('Payment verified with Paystack');
});
test('checkout links only permit the exact hosted provider address', () => {
    expect(safeCheckoutUrl('https://checkout.paystack.com/abc-12')).toBe('https://checkout.paystack.com/abc-12');
    // eslint-disable-next-line no-script-url -- Adversarial input is validated, never rendered or executed.
    for (const url of ['javascript:alert(1)', 'https://checkout.paystack.com.evil.test/a', 'https://checkout.paystack.com/a?x=y', 'https://user@checkout.paystack.com/a']) expect(safeCheckoutUrl(url)).toBeNull();
});
