import React, { useState, useMemo } from 'react';
import Icon from '../common/Icon';

const ClientPaymentsTab = ({
    user,
    deliveries = [],
    loading = false,
    onBook
}) => {
    const [statusFilter, setStatusFilter] = useState('all');
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedReceiptDelivery, setSelectedReceiptDelivery] = useState(null);

    // Compute financial metrics
    const totalSpent = useMemo(() => {
        return deliveries
            .filter(d => d.payment_status === 'paid' || ['delivered', 'completed', 'in_transit', 'assigned'].includes(d.status))
            .reduce((acc, d) => acc + (Number(d.total_cost) || 0), 0);
    }, [deliveries]);

    const paidDeliveries = useMemo(() => {
        return deliveries.filter(d => (d.payment_status === 'paid') || ['delivered', 'completed'].includes(d.status));
    }, [deliveries]);

    const pendingPayments = useMemo(() => {
        return deliveries.filter(d => d.payment_status === 'pending' && !['delivered', 'completed', 'cancelled'].includes(d.status));
    }, [deliveries]);

    const pendingAmount = useMemo(() => {
        return pendingPayments.reduce((acc, d) => acc + (Number(d.total_cost) || 0), 0);
    }, [pendingPayments]);

    const avgCost = deliveries.length > 0 ? Math.round(totalSpent / deliveries.length) : 0;

    // Filter and search
    const filteredDeliveries = useMemo(() => {
        return deliveries.filter(d => {
            const isPaid = d.payment_status === 'paid' || ['delivered', 'completed'].includes(d.status);
            if (statusFilter === 'paid' && !isPaid) return false;
            if (statusFilter === 'pending' && isPaid) return false;

            if (searchTerm.trim()) {
                const q = searchTerm.toLowerCase().trim();
                const matchRef = (d.tracking_number && d.tracking_number.toLowerCase().includes(q)) || String(d.id).includes(q);
                const matchDesc = d.item_description && d.item_description.toLowerCase().includes(q);
                const matchAddr = (d.delivery_address && d.delivery_address.toLowerCase().includes(q)) || (d.pickup_address && d.pickup_address.toLowerCase().includes(q));
                if (!matchRef && !matchDesc && !matchAddr) return false;
            }
            return true;
        });
    }, [deliveries, statusFilter, searchTerm]);

    const handlePrintReceipt = () => {
        window.print();
    };

    return (
        <div className="client-payments-tab">
            {/* Header */}
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div>
                    <h4 className="fw-bold mb-1 text-dark">Payments & Receipts</h4>
                    <p className="text-muted small mb-0">
                        Review delivery charges, payment records, and download official receipt vouchers
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
                            Across {paidDeliveries.length} completed transactions
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
                            {pendingPayments.length} order{pendingPayments.length === 1 ? '' : 's'} awaiting payment
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
                            {deliveries.length}
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
                                onChange={(e) => setSearchTerm(e.target.value)}
                            />
                            {searchTerm && (
                                <button className="btn btn-outline-secondary" onClick={() => setSearchTerm('')}>
                                    ✕
                                </button>
                            )}
                        </div>
                    </div>

                    <div className="col-md-6 d-flex justify-content-md-end gap-1">
                        {[
                            { id: 'all', label: 'All Transactions' },
                            { id: 'paid', label: 'Paid & Settled' },
                            { id: 'pending', label: 'Pending' }
                        ].map(tab => (
                            <button
                                key={tab.id}
                                className={`btn btn-sm ${statusFilter === tab.id ? 'btn-dark fw-semibold' : 'btn-outline-secondary'}`}
                                onClick={() => setStatusFilter(tab.id)}
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
                                        <th className="text-end">Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredDeliveries.map(item => {
                                        const isPaid = item.payment_status === 'paid' || ['delivered', 'completed'].includes(item.status);
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
                                                            Pending
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="fw-bold text-dark">
                                                    ₦{Number(item.total_cost || 0).toLocaleString()}
                                                </td>
                                                <td className="text-end">
                                                    <button
                                                        className="btn btn-sm btn-outline-secondary py-1 px-2 fw-semibold"
                                                        style={{ fontSize: '0.8rem' }}
                                                        onClick={() => setSelectedReceiptDelivery(item)}
                                                    >
                                                        🧾 View Receipt
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
                                const isPaid = item.payment_status === 'paid' || ['delivered', 'completed'].includes(item.status);
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
                                                <span className="badge bg-warning-subtle text-dark border">Pending</span>
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
                                            <button
                                                className="btn btn-sm btn-outline-primary"
                                                onClick={() => setSelectedReceiptDelivery(item)}
                                            >
                                                Receipt →
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
            {selectedReceiptDelivery && (
                <div
                    className="modal show d-block app-modal-backdrop"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Delivery Receipt Voucher"
                >
                    <div className="modal-dialog modal-dialog-centered">
                        <div className="modal-content border-0 shadow-lg" style={{ borderRadius: '14px', overflow: 'hidden' }}>
                            <div className="modal-header bg-dark text-white px-4 py-3">
                                <div>
                                    <h5 className="modal-title fw-bold mb-0">Delivery Receipt Voucher</h5>
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
                                        <span className="badge bg-success text-white">Payment Confirmed</span>
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
                                    <div className="d-flex justify-content-between small text-muted mb-1">
                                        <span>Base Dispatch Charge</span>
                                        <span>₦{Math.max(500, Math.round(Number(selectedReceiptDelivery.total_cost || 0) * 0.4)).toLocaleString()}</span>
                                    </div>
                                    <div className="d-flex justify-content-between small text-muted mb-2">
                                        <span>Distance & Transit Fare</span>
                                        <span>₦{Math.max(0, Math.round(Number(selectedReceiptDelivery.total_cost || 0) * 0.6)).toLocaleString()}</span>
                                    </div>
                                    <div className="d-flex justify-content-between fs-5 fw-bold text-dark pt-2 border-top">
                                        <span>Total Amount Paid</span>
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
                                    🖨️ Print / Save Receipt
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default ClientPaymentsTab;
