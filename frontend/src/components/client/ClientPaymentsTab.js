import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiGet } from '../../api/client';
import Pagination from '../common/Pagination';
import { isPaidDelivery } from '../../utils/payments';
import PaymentReviewPanel from '../common/PaymentReviewPanel';

const ClientPaymentsTab = ({
    user,
    onBook
}) => {
    const [statusFilter, setStatusFilter] = useState('all');
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedReceiptDelivery, setSelectedReceiptDelivery] = useState(null);
    const [paymentDeliveryId, setPaymentDeliveryId] = useState(null);

    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(25);
    const listing = useQuery({
        queryKey: ['client', 'payments', user?.id, page, limit, statusFilter, searchTerm],
        queryFn: ({ signal }) => apiGet(`/deliveries/my-requests?${new URLSearchParams({ page, limit, payment: statusFilter, search: searchTerm })}`, { signal }),
    });
    const summary = useQuery({
        queryKey: ['client', 'payment-summary', user?.id],
        queryFn: ({ signal }) => apiGet('/deliveries/payment-summary', { signal }),
    });
    const deliveries = listing.data?.data || [];
    const filteredDeliveries = deliveries;
    const totals = summary.data?.data || {};
    const totalSpent = Number(totals.paid_amount || 0);
    const pendingAmount = Number(totals.pending_amount || 0);
    const paidCount = Number(totals.paid_count || 0);
    const pendingCount = Number(totals.pending_count || 0);
    const avgCost = paidCount ? Math.round(totalSpent / paidCount) : 0;
    const loading = listing.isLoading || summary.isLoading;

    const handlePrintReceipt = () => {
        window.print();
    };

    if (listing.isError || summary.isError) return <div role="alert" className="alert alert-danger">Payment records are unavailable. <button onClick={() => { listing.refetch(); summary.refetch(); }}>Retry</button></div>;

    return (
        <div className="client-payments-tab">
            {/* Header */}
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div>
                    <h4 className="fw-bold mb-1 text-dark">Delivery Charges & Payment Status</h4>
                    <p className="text-muted small mb-0">
                        Review all your shipment charges. Totals cover your account; search and filters apply to the list below.
                    </p>
                </div>
                <div className="d-flex align-items-center gap-2">
                    <button className="btn btn-sm btn-primary fw-semibold" onClick={onBook}>
                        + Book New Shipment
                    </button>
                </div>
            </div>

            {/* KPI Cards */}
            <div className="row g-3 mb-4">
                <div className="col-sm-6 col-xl-3">
                    <div className="card border-0 shadow-sm custom-card p-3 h-100">
                        <div className="d-flex justify-content-between align-items-start mb-2">
                            <span className="fs-3">💳</span>
                            <span className="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                Settled
                            </span>
                        </div>
                        <h6 className="text-muted small fw-bold mb-1 text-uppercase" style={{ letterSpacing: '0.5px' }}>Total Amount Paid</h6>
                        <div className="display-6 fw-bold text-dark mb-1" style={{ fontSize: '1.6rem' }}>
                            ₦{totalSpent.toLocaleString()}
                        </div>
                        <small className="text-muted" style={{ fontSize: '0.8rem' }}>
                            Across {paidCount} shipments marked paid
                        </small>
                    </div>
                </div>

                <div className="col-sm-6 col-xl-3">
                    <div className="card border-0 shadow-sm custom-card p-3 h-100">
                        <div className="d-flex justify-content-between align-items-start mb-2">
                            <span className="fs-3">⏳</span>
                            <span className="badge rounded-pill bg-warning-subtle text-dark border border-warning-subtle px-2 py-1">
                                Pending
                            </span>
                        </div>
                        <h6 className="text-muted small fw-bold mb-1 text-uppercase" style={{ letterSpacing: '0.5px' }}>Pending Settlement</h6>
                        <div className="display-6 fw-bold text-dark mb-1" style={{ fontSize: '1.6rem' }}>
                            ₦{pendingAmount.toLocaleString()}
                        </div>
                        <small className="text-muted" style={{ fontSize: '0.8rem' }}>
                            {pendingCount} order{pendingCount === 1 ? '' : 's'} awaiting payment
                        </small>
                    </div>
                </div>

                <div className="col-sm-6 col-xl-3">
                    <div className="card border-0 shadow-sm custom-card p-3 h-100">
                        <div className="d-flex justify-content-between align-items-start mb-2">
                            <span className="fs-3">📦</span>
                            <span className="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                Volume
                            </span>
                        </div>
                        <h6 className="text-muted small fw-bold mb-1 text-uppercase" style={{ letterSpacing: '0.5px' }}>Total Deliveries</h6>
                        <div className="display-6 fw-bold text-dark mb-1" style={{ fontSize: '1.6rem' }}>
                            {Number(totals.total || 0)}
                        </div>
                        <small className="text-muted" style={{ fontSize: '0.8rem' }}>
                            Kano dispatch & courier requests
                        </small>
                    </div>
                </div>

                <div className="col-sm-6 col-xl-3">
                    <div className="card border-0 shadow-sm custom-card p-3 h-100">
                        <div className="d-flex justify-content-between align-items-start mb-2">
                            <span className="fs-3">📊</span>
                            <span className="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1">
                                Average
                            </span>
                        </div>
                        <h6 className="text-muted small fw-bold mb-1 text-uppercase" style={{ letterSpacing: '0.5px' }}>Avg Fare / Delivery</h6>
                        <div className="display-6 fw-bold text-dark mb-1" style={{ fontSize: '1.6rem' }}>
                            ₦{avgCost.toLocaleString()}
                        </div>
                        <small className="text-muted" style={{ fontSize: '0.8rem' }}>
                            Based on distance & weight
                        </small>
                    </div>
                </div>
            </div>

            {/* Filter & Search Controls */}
            <div className="card border-0 shadow-sm custom-card p-4">
                <div className="row g-2 align-items-center mb-4">
                    <div className="col-md-6">
                        <div className="input-group input-group-sm">
                            <span className="input-group-text bg-white">🔍</span>
                            <input
                                type="text"
                                className="form-control"
                                placeholder="Search by Tracking ID, item description, or destination..."
                                value={searchTerm}
                                onChange={(e) => { setSearchTerm(e.target.value); setPage(1); }}
                            />
                            {searchTerm && (
                                <button className="btn btn-outline-secondary" aria-label="Clear search" onClick={() => { setSearchTerm(''); setPage(1); }}>
                                    ✕
                                </button>
                            )}
                        </div>
                    </div>

                    <div className="col-md-6 d-flex justify-content-md-end gap-1">
                        {[
                            { id: 'all', label: 'All Transactions' },
                            { id: 'paid', label: 'Paid' },
                            { id: 'pending', label: 'Pending' }
                        ].map(tab => (
                            <button
                                key={tab.id}
                                className={`btn btn-sm ${statusFilter === tab.id ? 'btn-dark fw-semibold' : 'btn-outline-secondary'}`}
                                onClick={() => { setStatusFilter(tab.id); setPage(1); }}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Table */}
                {loading ? (
                    <div className="text-center py-5 text-muted">
                        <div className="spinner-border spinner-border-sm text-primary me-2" role="status" />
                        Loading payment history...
                    </div>
                ) : filteredDeliveries.length === 0 ? (
                    <div className="text-center py-5 bg-light rounded border">
                        <div className="fs-1 mb-2">💳</div>
                        <h6 className="fw-bold">No payment transactions found</h6>
                        <p className="text-muted small mb-3">
                            {deliveries.length === 0
                                ? 'You have not booked any shipments yet.'
                                : 'No payments match your filter criteria.'}
                        </p>
                        {deliveries.length === 0 && (
                            <button className="btn btn-sm btn-primary fw-semibold" onClick={onBook}>
                                Book a Delivery
                            </button>
                        )}
                    </div>
                ) : (
                    <>
                        {/* Desktop Table */}
                        <div className="table-responsive d-none d-md-block">
                            <table className="table table-hover align-middle mb-0">
                                <thead className="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Tracking Ref</th>
                                        <th>Item Description</th>
                                        <th>Route</th>
                                        <th>Payment Status</th>
                                        <th>Fare</th>
                                        <th className="text-end">Charge Summary</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredDeliveries.map(item => {
                                        const isPaid = isPaidDelivery(item);
                                        return (
                                            <tr key={item.id}>
                                                <td className="small text-muted" style={{ whiteSpace: 'nowrap' }}>
                                                    {item.request_time ? new Date(item.request_time).toLocaleDateString() : 'Recent'}
                                                </td>
                                                <td>
                                                    <span className="fw-bold text-dark font-monospace" style={{ fontSize: '0.88rem' }}>
                                                        {item.tracking_number || `#${item.id}`}
                                                    </span>
                                                    <small className="text-muted d-block" style={{ fontSize: '0.72rem' }}>
                                                        {item.service_type?.replace('_', ' ') || 'Express'}
                                                    </small>
                                                </td>
                                                <td>
                                                    <span className="fw-semibold text-dark small d-block">{item.item_description}</span>
                                                    <small className="text-muted">{item.item_weight}kg</small>
                                                </td>
                                                <td>
                                                    <small className="d-block text-truncate text-muted" style={{ maxWidth: '200px' }}>
                                                        📍 {item.pickup_address}
                                                    </small>
                                                    <small className="d-block text-truncate text-dark" style={{ maxWidth: '200px' }}>
                                                        🏁 {item.delivery_address}
                                                    </small>
                                                </td>
                                                <td>
                                                    {isPaid ? (
                                                        <span className="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                            ✓ Paid
                                                        </span>
                                                    ) : (
                                                        <span className="badge bg-warning-subtle text-dark border border-warning-subtle px-2 py-1">
                                                            {item.payment_status || 'unpaid'}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="fw-bold text-dark">
                                                    ₦{Number(item.total_cost || 0).toLocaleString()}
                                                </td>
                                                <td className="text-end">
                                                    <button className="btn btn-sm btn-primary me-2" onClick={() => setPaymentDeliveryId(item.id)}>Review &amp; pay</button>
                                                    <button
                                                        className="btn btn-sm btn-outline-secondary py-1 px-2 fw-semibold"
                                                        style={{ fontSize: '0.8rem' }}
                                                        onClick={() => setSelectedReceiptDelivery(item)}
                                                    >
                                                        🧾 View Charge
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/* Mobile Cards */}
                        <div className="d-md-none d-flex flex-column gap-3">
                            {filteredDeliveries.map(item => {
                                const isPaid = isPaidDelivery(item);
                                return (
                                    <div key={item.id} className="p-3 border rounded bg-white shadow-sm">
                                        <div className="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <span className="fw-bold text-dark font-monospace d-block">
                                                    {item.tracking_number || `#${item.id}`}
                                                </span>
                                                <small className="text-muted">{item.item_description}</small>
                                            </div>
                                            {isPaid ? (
                                                <span className="badge bg-success-subtle text-success border">Paid</span>
                                            ) : (
                                                <span className="badge bg-warning-subtle text-dark border text-capitalize">{item.payment_status || 'unpaid'}</span>
                                            )}
                                        </div>
                                        <div className="small text-muted mb-2">
                                            <div className="text-truncate">🏁 {item.delivery_address}</div>
                                            <div>📅 {item.request_time ? new Date(item.request_time).toLocaleDateString() : 'Recent'}</div>
                                        </div>
                                        <div className="d-flex justify-content-between align-items-center pt-2 border-top">
                                            <span className="fw-bold text-dark">
                                                ₦{Number(item.total_cost || 0).toLocaleString()}
                                            </span>
                                            <button className="btn btn-sm btn-primary" onClick={() => setPaymentDeliveryId(item.id)}>Review &amp; pay</button>
                                            <button
                                                className="btn btn-sm btn-outline-primary"
                                                onClick={() => setSelectedReceiptDelivery(item)}
                                            >
                                                View Charge →
                                            </button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}
            </div>

            {/* Receipt Modal */}
            {paymentDeliveryId && <PaymentReviewPanel key={paymentDeliveryId} deliveryId={paymentDeliveryId} onClose={() => setPaymentDeliveryId(null)} />}
            {selectedReceiptDelivery && (
                <div
                    className="modal show d-block app-modal-backdrop"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Delivery charge summary"
                >
                    <div className="modal-dialog modal-dialog-centered">
                        <div className="modal-content border-0 shadow-lg" style={{ borderRadius: '14px', overflow: 'hidden' }}>
                            <div className="modal-header bg-dark text-white px-4 py-3">
                                <div>
                                    <h5 className="modal-title fw-bold mb-0">Delivery Charge Summary</h5>
                                    <small className="text-muted font-monospace">
                                        Ref: {selectedReceiptDelivery.tracking_number || `#${selectedReceiptDelivery.id}`}
                                    </small>
                                </div>
                                <button
                                    type="button"
                                    className="btn-close btn-close-white"
                                    onClick={() => setSelectedReceiptDelivery(null)}
                                    aria-label="Close"
                                />
                            </div>

                            <div className="modal-body p-4" id="printable-receipt-area">
                                {/* Company Banner */}
                                <div className="text-center pb-3 border-bottom mb-3">
                                    <h5 className="fw-bold mb-1 text-danger">TWEAK INSIGHT LOGISTICS</h5>
                                    <small className="text-muted d-block">Metropolitan Courier & Freight Dispatch Services</small>
                                    <small className="text-muted">Kano Metropolis, Kano State, Nigeria</small>
                                </div>

                                {/* Transaction Info */}
                                <div className="row g-2 small mb-3">
                                    <div className="col-6">
                                        <span className="text-muted d-block">Client:</span>
                                        <strong>{user?.full_name || 'Valued Client'}</strong>
                                    </div>
                                    <div className="col-6 text-end">
                                        <span className="text-muted d-block">Date & Time:</span>
                                        <strong>
                                            {selectedReceiptDelivery.request_time ? new Date(selectedReceiptDelivery.request_time).toLocaleString() : new Date().toLocaleDateString()}
                                        </strong>
                                    </div>
                                    <div className="col-6 mt-2">
                                        <span className="text-muted d-block">Service:</span>
                                        <span className="badge bg-light text-dark border">
                                            {selectedReceiptDelivery.service_type?.replace('_', ' ') || 'Express Delivery'}
                                        </span>
                                    </div>
                                    <div className="col-6 mt-2 text-end">
                                        <span className="text-muted d-block">Status:</span>
                                        <span className="badge bg-secondary text-white text-capitalize">{selectedReceiptDelivery.payment_status || 'unpaid'}</span>
                                    </div>
                                </div>

                                {/* Route Details */}
                                <div className="p-3 bg-light rounded border mb-3 small">
                                    <div className="mb-2">
                                        <span className="text-muted d-block" style={{ fontSize: '0.75rem' }}>PICKUP LOCATION</span>
                                        <div className="fw-semibold text-dark">{selectedReceiptDelivery.pickup_address}</div>
                                    </div>
                                    <div>
                                        <span className="text-muted d-block" style={{ fontSize: '0.75rem' }}>DELIVERY DESTINATION</span>
                                        <div className="fw-semibold text-dark">{selectedReceiptDelivery.delivery_address}</div>
                                    </div>
                                </div>

                                {/* Item Info */}
                                <div className="p-3 bg-light rounded border mb-3 small">
                                    <div className="d-flex justify-content-between">
                                        <span className="text-muted">Cargo:</span>
                                        <span className="fw-semibold">{selectedReceiptDelivery.item_description}</span>
                                    </div>
                                    <div className="d-flex justify-content-between mt-1">
                                        <span className="text-muted">Weight / Category:</span>
                                        <span className="fw-semibold">{selectedReceiptDelivery.item_weight}kg · {selectedReceiptDelivery.item_category}</span>
                                    </div>
                                    <div className="d-flex justify-content-between mt-1">
                                        <span className="text-muted">Recipient:</span>
                                        <span className="fw-semibold">{selectedReceiptDelivery.recipient_name || selectedReceiptDelivery.delivery_contact_name || 'Standard Handover'}</span>
                                    </div>
                                </div>

                                {/* Fare Breakdown */}
                                <div className="border-top pt-3">
                                    <div className="d-flex justify-content-between fs-5 fw-bold text-dark pt-2 border-top">
                                        <span>Total Delivery Charge</span>
                                        <span className="text-primary">₦{Number(selectedReceiptDelivery.total_cost || 0).toLocaleString()}</span>
                                    </div>
                                </div>
                            </div>

                            <div className="modal-footer bg-light px-4 py-3 d-flex justify-content-between">
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary btn-sm"
                                    onClick={() => setSelectedReceiptDelivery(null)}
                                >
                                    Close
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-primary btn-sm fw-semibold"
                                    onClick={handlePrintReceipt}
                                >
                                    🖨️ Print / Save Summary
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
            <Pagination pagination={listing.data?.pagination} onPageChange={setPage} onLimitChange={value => { setLimit(value); setPage(1); }} />
        </div>
    );
};

export default ClientPaymentsTab;
