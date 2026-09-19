import React, { useState, useMemo } from 'react';
import Pagination from '../common/Pagination';
import CursorPagination from '../common/CursorPagination';
import EmptyState from '../common/EmptyState';
import { TableSkeleton } from '../common/SkeletonLoader';
import Icon from '../common/Icon';
import ShipmentOverviewCards from './ShipmentOverviewCards';

const ShipmentHistoryTab = ({ 
    deliveries = [], 
    loading, 
    pagination,
    paginationMode = 'cursor',
    onTogglePaginationMode,
    hasMore = false,
    isLoadingMore = false,
    onLoadMore,
    onPageChange, 
    onLimitChange, 
    onRefresh, 
    onTrack, 
    onStart,
    initialStatusFilter = 'all',
    onViewReceipt
}) => {
    const [searchTerm, setSearchTerm] = useState('');
    const [statusFilter, setStatusFilter] = useState(initialStatusFilter);

    React.useEffect(() => {
        if (initialStatusFilter) {
            setStatusFilter(initialStatusFilter);
        }
    }, [initialStatusFilter]);
    const [sortBy, setSortBy] = useState('newest');

    // Filter and sort deliveries locally on the loaded set
    const filteredAndSortedDeliveries = useMemo(() => {
        let result = [...deliveries];

        // 1. Status Filter
        if (statusFilter === 'active') {
            result = result.filter(d =>
                ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived'].includes(d.status)
            );
        } else if (statusFilter === 'delivered') {
            result = result.filter(d => ['delivered', 'completed'].includes(d.status));
        } else if (statusFilter === 'pending') {
            result = result.filter(d => ['pending', 'under_review', 'broadcasted'].includes(d.status));
        } else if (statusFilter === 'cancelled') {
            result = result.filter(d => ['cancelled', 'rejected', 'failed'].includes(d.status));
        }

        // 2. Search Query Filter
        if (searchTerm.trim()) {
            const q = searchTerm.toLowerCase().trim();
            result = result.filter(d => 
                (d.tracking_number && d.tracking_number.toLowerCase().includes(q)) ||
                (d.id && String(d.id).includes(q)) ||
                (d.item_description && d.item_description.toLowerCase().includes(q)) ||
                (d.pickup_address && d.pickup_address.toLowerCase().includes(q)) ||
                (d.delivery_address && d.delivery_address.toLowerCase().includes(q)) ||
                (d.delivery_contact_name && d.delivery_contact_name.toLowerCase().includes(q))
            );
        }

        // 3. Sorting
        result.sort((a, b) => {
            if (sortBy === 'newest') {
                return (b.id || 0) - (a.id || 0);
            } else if (sortBy === 'oldest') {
                return (a.id || 0) - (b.id || 0);
            } else if (sortBy === 'cost_desc') {
                return (Number(b.total_cost) || 0) - (Number(a.total_cost) || 0);
            } else if (sortBy === 'cost_asc') {
                return (Number(a.total_cost) || 0) - (Number(b.total_cost) || 0);
            }
            return 0;
        });

        return result;
    }, [deliveries, statusFilter, searchTerm, sortBy]);

    const handleClearFilters = () => {
        setSearchTerm('');
        setStatusFilter('all');
        setSortBy('newest');
    };

    const getStatusBadge = (status) => {
        const norm = status ? status.toLowerCase() : '';
        if (['delivered', 'completed'].includes(norm)) {
            return <span className="status-pill status-delivered">✓ Delivered</span>;
        }
        if (['in_transit', 'arrived'].includes(norm)) {
            return <span className="status-pill status-in_transit">🚀 In Transit</span>;
        }
        if (['picked_up', 'assigned', 'driver_en_route'].includes(norm)) {
            return <span className="status-pill status-assigned">🛵 Dispatched</span>;
        }
        if (['pending', 'under_review', 'broadcasted'].includes(norm)) {
            return <span className="status-pill status-pending">⏳ Pending</span>;
        }
        return <span className="status-pill status-cancelled">{status ? status.replaceAll('_', ' ') : 'Unknown'}</span>;
    };

    return (
        <div className="shipment-history-container">
            {/* Overview Status KPI Cards */}
            <ShipmentOverviewCards 
                deliveries={deliveries} 
                activeStatusFilter={statusFilter} 
                onSelectFilter={setStatusFilter} 
            />

            <div className="card border-0 shadow-sm custom-card p-4">
                <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                    <div>
                        <h4 className="fw-bold mb-1">My Shipments & Records</h4>
                        <p className="text-muted small mb-0">Search, filter, track, and retrieve Proof of Delivery certificates</p>
                    </div>
                    <div className="d-flex align-items-center gap-2 flex-wrap">
                        {/* Keyset Cursor Stream vs Paged Mode Toggle */}
                        <div className="btn-group btn-group-sm" role="group" aria-label="Pagination Mode">
                            <button
                                type="button"
                                className={`btn ${paginationMode === 'cursor' ? 'btn-primary' : 'btn-outline-secondary'}`}
                                onClick={() => onTogglePaginationMode?.('cursor')}
                                title="O(1) keyset index seek without offset scanning"
                            >
                                ⚡ Keyset Stream
                            </button>
                            <button
                                type="button"
                                className={`btn ${paginationMode === 'paged' ? 'btn-primary' : 'btn-outline-secondary'}`}
                                onClick={() => onTogglePaginationMode?.('paged')}
                                title="Classic numbered pages"
                            >
                                📄 Paged View
                            </button>
                        </div>

                        <button className="btn btn-sm btn-outline-primary" onClick={onRefresh} title="Refresh data">
                            <Icon name="refresh" size={16} /> Refresh
                        </button>
                        <button className="btn btn-sm btn-primary fw-semibold" onClick={onStart}>
                            + Book Delivery
                        </button>
                    </div>
                </div>

                {/* Filter and Search Bar */}
                <div className="p-3 bg-light rounded border mb-4">
                    <div className="row g-2 align-items-center">
                        {/* Search Input */}
                        <div className="col-lg-5 col-md-12">
                            <div className="input-group input-group-sm">
                                <span className="input-group-text bg-white border-end-0">
                                    🔍
                                </span>
                                <input
                                    type="text"
                                    className="form-control border-start-0"
                                    placeholder="Search by Tracking ID, item, address, or recipient..."
                                    value={searchTerm}
                                    onChange={e => setSearchTerm(e.target.value)}
                                />
                                {searchTerm && (
                                    <button
                                        className="btn btn-outline-secondary"
                                        type="button"
                                        onClick={() => setSearchTerm('')}
                                        title="Clear search"
                                    >
                                        ✕
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* Status Filter Pills */}
                        <div className="col-lg-4 col-sm-7">
                            <div className="d-flex align-items-center gap-1 overflow-x-auto pb-1" style={{ whiteSpace: 'nowrap' }}>
                                {[
                                    { id: 'all', label: 'All' },
                                    { id: 'active', label: 'Active' },
                                    { id: 'pending', label: 'Pending' },
                                    { id: 'delivered', label: 'Delivered' },
                                    { id: 'cancelled', label: 'Cancelled' }
                                ].map(tab => (
                                    <button
                                        key={tab.id}
                                        type="button"
                                        className={`btn btn-sm py-1 px-2 ${
                                            statusFilter === tab.id
                                                ? 'btn-dark fw-bold'
                                                : 'btn-outline-secondary'
                                        }`}
                                        style={{ fontSize: '0.78rem' }}
                                        onClick={() => setStatusFilter(tab.id)}
                                    >
                                        {tab.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Sort Selector */}
                        <div className="col-lg-3 col-sm-5 d-flex align-items-center justify-content-sm-end gap-1">
                            <label className="text-muted small mb-0" style={{ fontSize: '0.8rem', whiteSpace: 'nowrap' }}>Sort:</label>
                            <select
                                className="form-select form-select-sm"
                                style={{ width: 'auto', fontSize: '0.8rem' }}
                                value={sortBy}
                                onChange={e => setSortBy(e.target.value)}
                            >
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                                <option value="cost_desc">Cost (High → Low)</option>
                                <option value="cost_asc">Cost (Low → High)</option>
                            </select>
                        </div>
                    </div>
                </div>

                {loading ? (
                    <TableSkeleton rows={5} cols={6} />
                ) : deliveries.length === 0 ? (
                    <EmptyState
                        icon="🚚"
                        title="You have not made any shipments yet"
                        actionLabel="Request your first delivery"
                        onAction={onStart}
                    >
                        Create a Kano delivery request in a few minutes and follow its progress here.
                    </EmptyState>
                ) : filteredAndSortedDeliveries.length === 0 ? (
                    <div className="alert alert-light text-center py-5 border">
                        <div className="fs-3 mb-2">🔍</div>
                        <h6 className="fw-bold">No shipments match your search criteria</h6>
                        <p className="text-muted small mb-3">
                            Try adjusting your search terms or clearing the current status filter.
                        </p>
                        <button className="btn btn-sm btn-outline-secondary" onClick={handleClearFilters}>
                            Clear Search & Filters
                        </button>
                    </div>
                ) : (
                    <>
                        {/* Desktop Table */}
                        <div className="table-responsive client-history-table d-none d-md-block">
                            <table className="table table-hover align-middle">
                                <thead className="table-light">
                                    <tr>
                                        <th>Tracking Reference</th>
                                        <th>Date</th>
                                        <th>Cargo & Details</th>
                                        <th>Origin → Destination</th>
                                        <th>Status</th>
                                        <th>Fare</th>
                                        <th className="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredAndSortedDeliveries.map(item => (
                                        <tr key={item.id}>
                                            <td data-label="Reference">
                                                <div className="fw-bold text-dark" style={{ fontFamily: 'monospace', letterSpacing: '0.5px' }}>
                                                    {item.tracking_number || `#${item.id}`}
                                                </div>
                                                <small className="text-muted d-block" style={{ fontSize: '0.75rem' }}>
                                                    {item.service_type ? item.service_type.replace('_', ' ') : 'Standard Delivery'}
                                                </small>
                                            </td>
                                            <td data-label="Date" className="small text-muted" style={{ whiteSpace: 'nowrap' }}>
                                                {item.request_time ? new Date(item.request_time).toLocaleDateString() : 'Recent'}
                                            </td>
                                            <td data-label="Item">
                                                <div className="fw-semibold text-dark">{item.item_description}</div>
                                                <small className="text-muted">
                                                    {item.item_weight}kg · {item.item_category}
                                                    {item.driver_name ? ` · 🛵 ${item.driver_name}` : ''}
                                                </small>
                                            </td>
                                            <td data-label="Route">
                                                <small className="d-block text-truncate text-dark" style={{ maxWidth: '220px' }} title={item.pickup_address}>
                                                    📍 <span className="text-muted">From:</span> {item.pickup_address}
                                                </small>
                                                <small className="d-block text-truncate text-dark" style={{ maxWidth: '220px' }} title={item.delivery_address}>
                                                    🏁 <span className="text-muted">To:</span> {item.delivery_address}
                                                </small>
                                            </td>
                                            <td data-label="Status">
                                                {getStatusBadge(item.status)}
                                            </td>
                                            <td data-label="Cost" className="fw-bold text-dark">
                                                ₦{Number(item.total_cost).toLocaleString()}
                                            </td>
                                            <td data-label="Action" className="text-end">
                                                <div className="d-flex justify-content-end gap-1">
                                                    {onViewReceipt && (
                                                        <button
                                                            className="btn btn-sm btn-outline-secondary py-1 px-2"
                                                            style={{ fontSize: '0.78rem' }}
                                                            onClick={() => onViewReceipt(item)}
                                                            title="View Receipt Voucher"
                                                        >
                                                            🧾
                                                        </button>
                                                    )}
                                                    <button
                                                        className="btn btn-sm btn-primary px-3 fw-semibold shadow-sm text-nowrap"
                                                        onClick={() => onTrack(item)}
                                                    >
                                                        Track & POD →
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Mobile Responsive Cards */}
                        <div className="d-md-none d-flex flex-column gap-3">
                            {filteredAndSortedDeliveries.map(item => (
                                <div key={item.id} className="card p-3 border shadow-sm rounded-3">
                                    <div className="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span className="fw-bold text-dark d-block" style={{ fontFamily: 'monospace' }}>
                                                {item.tracking_number || `#${item.id}`}
                                            </span>
                                            <small className="text-muted">{item.item_description}</small>
                                        </div>
                                        {getStatusBadge(item.status)}
                                    </div>
                                    <hr className="my-2" />
                                    <div className="small mb-2">
                                        <div className="text-truncate mb-1">
                                            📍 <strong>Pick:</strong> {item.pickup_address}
                                        </div>
                                        <div className="text-truncate mb-1">
                                            🏁 <strong>Drop:</strong> {item.delivery_address}
                                        </div>
                                        <div className="text-muted">
                                            📦 {item.item_category} · {item.item_weight}kg
                                            {item.driver_name ? ` · 🛵 ${item.driver_name}` : ''}
                                        </div>
                                        <div className="text-muted" style={{ fontSize: '0.75rem' }}>
                                            📅 {item.request_time ? new Date(item.request_time).toLocaleDateString() : 'Recent'}
                                        </div>
                                    </div>
                                    <div className="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                        <span className="fw-bold fs-6 text-dark">
                                            ₦{Number(item.total_cost).toLocaleString()}
                                        </span>
                                        <div className="d-flex gap-1">
                                            {onViewReceipt && (
                                                <button
                                                    className="btn btn-sm btn-outline-secondary px-2"
                                                    onClick={() => onViewReceipt(item)}
                                                >
                                                    🧾
                                                </button>
                                            )}
                                            <button
                                                className="btn btn-sm btn-primary px-3 fw-semibold"
                                                onClick={() => onTrack(item)}
                                            >
                                                Track & POD →
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>

                        {paginationMode === 'cursor' ? (
                            <CursorPagination
                                hasMore={hasMore}
                                isLoadingMore={isLoadingMore}
                                onLoadMore={onLoadMore}
                                loadedCount={deliveries.length}
                                itemLabel="shipments"
                                buttonText="Load More Shipments (Keyset Seek)"
                            />
                        ) : (
                            <Pagination 
                                pagination={pagination} 
                                onPageChange={onPageChange} 
                                onLimitChange={onLimitChange} 
                            />
                        )}
                    </>
                )}
            </div>
        </div>
    );
};

export default ShipmentHistoryTab;
