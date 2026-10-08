import { paymentSummary } from './payments';

test('delivery completion never implies payment or payout', () => {
    const rows = [
        { id: 1, status: 'delivered', payment_status: 'unpaid', total_cost: '900.00' },
        { id: 2, status: 'completed', payment_status: 'pending', total_cost: '600.00' },
        { id: 3, status: 'assigned', payment_status: 'paid', total_cost: '1200.50' },
        { id: 4, status: 'cancelled', payment_status: 'unpaid', total_cost: '400.00' },
        { id: 5, status: 'completed', payment_status: 'refunded', total_cost: '800.00' },
        { id: 6, status: 'delivered', payment_status: 'waived', total_cost: '500.00' },
    ];
    const summary = paymentSummary(rows);
    expect(summary.paid.map(row => row.id)).toEqual([3]);
    expect(summary.pending.map(row => row.id)).toEqual([1, 2]);
    expect(summary.paidAmount).toBe(1200.5);
    expect(summary.pendingAmount).toBe(1500);
});

test('an empty payment list has zero amounts', () => {
    expect(paymentSummary([])).toEqual({ paid: [], pending: [], paidAmount: 0, pendingAmount: 0 });
});
