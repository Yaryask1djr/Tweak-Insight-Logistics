import { Link, useLocation } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from './AuthContext';
import { apiPost } from '../api/client';
import { PaymentEvidence } from './common/PaymentReviewPanel';

export default function PaymentReturn() {
    const { user, isRestoring } = useAuth();
    const location = useLocation();
    const reference = new URLSearchParams(location.search).get('reference') || '';
    const valid = /^TILPAY-[a-f0-9]{32}$/.test(reference);
    const result = useQuery({
        queryKey: ['payment-return', user?.id, reference],
        queryFn: () => apiPost('/deliveries/payments/verify', { reference }),
        enabled: valid && !isRestoring && user?.role === 'client',
        retry: false, refetchOnWindowFocus: false,
    });
    return <main className="container py-5" style={{ maxWidth: 720 }}>
        <h1 className="h3">Payment verification</h1>
        {!valid ? <p role="alert">This return link has no valid payment reference. Check payment from your dashboard.</p>
            : isRestoring ? <p role="status">Restoring your session…</p>
                : !user ? <p>Sign in to verify this payment. <Link to={`/login?redirect=${encodeURIComponent('/payment/return?reference=' + reference)}`}>Sign in</Link></p>
                    : user.role !== 'client' ? <p role="alert">Sign in with the client account that owns this delivery.</p>
                        : <>
                            {result.isFetching && <p role="status">Checking the transaction with Paystack…</p>}
                            {result.isError && <p role="alert">{result.error.response?.data?.message || 'Payment could not be confirmed. Try checking again.'}</p>}
                            {result.data?.data && <PaymentEvidence payment={result.data.data} />}
                            <button className="btn btn-outline-primary mb-3" disabled={result.isFetching} onClick={() => result.refetch()}>Check payment again</button>
                        </>}
        <p><Link to="/dashboard">Return to your dashboard</Link></p>
    </main>;
}
