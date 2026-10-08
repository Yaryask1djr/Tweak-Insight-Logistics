export const isPaidDelivery = delivery => delivery.payment_status === 'paid';
export const isPendingPayment = delivery =>
    ['unpaid', 'pending'].includes(delivery.payment_status)
    && !['cancelled', 'rejected', 'failed'].includes(delivery.status);

export const paymentSummary = deliveries => {
    const paid = deliveries.filter(isPaidDelivery);
    const pending = deliveries.filter(isPendingPayment);
    const sum = rows => rows.reduce((total, row) => total + (Number(row.total_cost) || 0), 0);
    return { paid, pending, paidAmount: sum(paid), pendingAmount: sum(pending) };
};
