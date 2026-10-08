import { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';

export const formatFare = fare => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(fare.amount_minor / 100);
export function safeCheckoutUrl(value) {
    if (typeof value !== 'string' || !/^https:\/\/checkout\.paystack\.com\/[A-Za-z0-9_-]+$/.test(value)) return null;
    return value;
}
export function PaymentEvidence({ payment }) {
    const held = payment.attempt?.status === 'review_required';
    return <>
        {payment.attempt?.environment === 'test' && <p className="alert alert-warning">Test payment — no live funds confirmed.</p>}
        {held ? <p role="status" className="alert alert-warning">Payment requires operations review. Do not pay again. Pickup is blocked.</p>
            : payment.receipt ? <p role="status" className="alert alert-success">Payment verified with Paystack: {formatFare(payment.receipt)}.</p>
                : <p role="status" className="alert alert-info">Payment has not been verified. Pickup remains blocked.</p>}
        {payment.receipt && <p className="small text-muted">Reference: {payment.receipt.reference}. Verified at {payment.receipt.verified_at} UTC.</p>}
    </>;
}

export default function PaymentReviewPanel({ deliveryId, admin = false, onClose, onChanged }) {
    const panel = useRef(null);
    useEffect(() => { panel.current?.focus(); }, []);
    const queryClient = useQueryClient();
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const query = useQuery({
        queryKey: ['delivery-payment', deliveryId, admin],
        queryFn: ({ signal }) => apiGet(`${admin ? '/admin' : ''}/deliveries/payment?delivery_id=${deliveryId}`, { signal }),
        retry: false,
    });
    const payment = query.data?.data;
    const minor = payment?.fare?.amount_minor;
    useEffect(() => { setAmount(minor == null ? '' : (minor / 100).toFixed(2)); setReason(''); }, [deliveryId, minor]);
    const refresh = async () => {
        await query.refetch();
        queryClient.invalidateQueries({ queryKey: ['client'] });
        queryClient.invalidateQueries({ queryKey: ['admin'] });
        onChanged?.();
    };
    const act = async (path, body) => {
        setBusy(true); setError('');
        try { await apiPost(path, body); await refresh(); }
        catch (e) { setError(e.response?.data?.message || 'Payment action could not be confirmed. Refresh and check the existing payment.'); await query.refetch(); }
        finally { setBusy(false); }
    };
    const checkout = payment?.can_pay ? safeCheckoutUrl(payment.attempt?.authorization_url) : null;
    return <section ref={panel} tabIndex={-1} className="card shadow-sm p-3 p-md-4 mb-4" aria-label="Final fare and payment">
        <div className="d-flex justify-content-between gap-3 mb-2">
            <h5>Final fare &amp; payment {payment?.tracking_number && <small className="d-block text-muted">{payment.tracking_number}</small>}</h5>
            <button className="btn btn-sm btn-outline-secondary" type="button" onClick={onClose}>Close payment review</button>
        </div>
        {query.isLoading && <p role="status">Loading payment record…</p>}
        {query.isError && <p role="alert" className="text-danger">Payment record is unavailable. <button onClick={() => query.refetch()}>Retry</button></p>}
        {error && <p role="alert" className="alert alert-danger">{error}</p>}
        {payment && <>
            {payment.fare ? <><p className="fs-5 mb-1">Approved final fare: <strong>{formatFare(payment.fare)}</strong> <small>(version {payment.fare.version})</small></p>
                <p className="text-muted">{payment.fare.reason}</p></> : <p>Operations must approve the final fare before you can pay.</p>}
            {payment.fare && !payment.fare_current && <p className="alert alert-warning">Shipment details changed after approval. Operations must review the fare.</p>}
            <PaymentEvidence payment={payment} />
            {!payment.payments_available && <p className="text-muted">Checkout is unavailable in this environment. Contact operations.</p>}
            {admin && <form onSubmit={event => { event.preventDefault(); act('/admin/deliveries/approve-fare', { delivery_id: deliveryId, expected_version: payment.fare?.version || 0, amount, reason }); }}>
                <fieldset disabled={busy || !payment.can_approve}>
                    <legend className="fs-6">Approve final delivery fare</legend>
                    <label className="form-label" htmlFor="final-fare-amount">Amount (NGN)</label>
                    <input id="final-fare-amount" className="form-control mb-2" inputMode="decimal" value={amount} onChange={e => setAmount(e.target.value)} required pattern="[0-9]+(\.[0-9]{1,2})?" maxLength={11} />
                    <label className="form-label" htmlFor="final-fare-reason">Approval reason</label>
                    <textarea id="final-fare-reason" className="form-control mb-3" value={reason} onChange={e => setReason(e.target.value)} required maxLength={500} />
                    <button className="btn btn-primary" type="submit">Approve final fare</button>
                </fieldset>
                {!payment.can_approve && <p className="small text-muted mt-2">Fare changes are locked after checkout starts or pickup occurs. Reconcile any existing payment with operations.</p>}
            </form>}
            {!admin && payment.can_pay && !checkout && <button className="btn btn-primary me-2" disabled={busy} onClick={() => act('/deliveries/payments/initialize', { delivery_id: deliveryId, fare_version: payment.fare.version })}>
                {busy ? 'Preparing checkout…' : `Accept fare & prepare payment of ${formatFare(payment.fare)}`}
            </button>}
            {checkout && !admin && <a className="btn btn-primary me-2" href={checkout} target="_blank" rel="noopener noreferrer">Continue to Paystack checkout</a>}
            {!admin && payment.attempt && <button className="btn btn-outline-primary me-2 mt-2" disabled={busy} onClick={() => act('/deliveries/payments/verify', { reference: payment.attempt.reference })}>Check payment with Paystack</button>}
            <button className="btn btn-outline-secondary mt-2" disabled={busy || query.isFetching} onClick={refresh}>Refresh payment record</button>
            {!admin && <p className="small text-muted mt-3 mb-0">The checkout opens securely on Paystack. Returning here does not confirm payment; we verify it with the provider.</p>}
        </>}
    </section>;
}
