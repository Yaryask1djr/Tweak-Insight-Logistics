import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import Pagination from '../common/Pagination';
import CursorPagination from '../common/CursorPagination';
import { showToast } from '../common/Toast';
import { TableSkeleton } from '../common/SkeletonLoader';
import QueryState from '../common/QueryState';
import SensitiveValue from '../common/SensitiveValue';

const DELIVERY_FILTER_STORAGE_KEY = 'til-admin-delivery-filters';
const DEFAULT_FILTERS = { status: 'all', limit: 15 };
const VALID_STATUSES = [
    'all',
    'pending',
    'under_review',
    'broadcasted',
    'assigned',
    'driver_en_route',
    'picked_up',
    'in_transit',
    'arrived',
    'delivered',
    'completed',
    'cancelled',
    'rejected',
    'failed',
];

const readSavedFilters = () => {
    try {
        const saved = JSON.parse(localStorage.getItem(DELIVERY_FILTER_STORAGE_KEY) || '{}');
        return {
            status: VALID_STATUSES.includes(saved.status) ? saved.status : DEFAULT_FILTERS.status,
            limit: [5, 10, 15, 25, 50].includes(Number(saved.limit))
                ? Number(saved.limit)
                : DEFAULT_FILTERS.limit,
        };
    } catch {
        return DEFAULT_FILTERS;
    }
};

const DeliveriesTab = () => {
    const [savedFilters] = useState(readSavedFilters);
    const [paginationMode, setPaginationMode] = useState('cursor');
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(savedFilters.limit);
    const [status, setStatus] = useState(savedFilters.status);
    const [searchTerm, setSearchTerm] = useState('');
    const [sortBy, setSortBy] = useState('newest');
    const [manualDriver, setManualDriver] = useState({});
    const [cursorItems, setCursorItems] = useState([]);
    const [nextCursor, setNextCursor] = useState(null);
    const [hasMore, setHasMore] = useState(false);
    const [isLoadingCursor, setIsLoadingCursor] = useState(false);
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    // Inline release-assignment reason state (replaces window.prompt)
    const [releaseId, setReleaseId] = useState(null);
    const [releaseReason, setReleaseReason] = useState('');
    const queryClient = useQueryClient();

    const fetchInitialCursor = async () => {
        setIsLoadingCursor(true);
        try {
            const res = await apiGet(`/admin/all-deliveries?status=${status}&limit=${limit}&cursor=`);
            const items = res.data?.deliveries || res.data?.data || [];
            setCursorItems(items);
            setNextCursor(res.data?.next_cursor ?? null);
            setHasMore(Boolean(res.data?.has_more));
        } catch (err) {
            console.error('Failed to fetch cursor deliveries', err);
        } finally {
            setIsLoadingCursor(false);
        }
    };

    const loadMoreCursor = async () => {
        if (!nextCursor || isLoadingMore) return;
        setIsLoadingMore(true);
        try {
            const res = await apiGet(`/admin/all-deliveries?status=${status}&limit=${limit}&cursor=${nextCursor}`);
            const newItems = res.data?.deliveries || res.data?.data || [];
            setCursorItems(prev => [...prev, ...newItems]);
            setNextCursor(res.data?.next_cursor ?? null);
            setHasMore(Boolean(res.data?.has_more));
        } catch (err) {
            console.error('Failed to load more cursor deliveries', err);
        } finally {
            setIsLoadingMore(false);
        }
    };

    useEffect(() => {
        if (paginationMode === 'cursor') {
            fetchInitialCursor();
        }
    }, [paginationMode, status, limit]);

    useEffect(() => {
        try {
            localStorage.setItem(
                DELIVERY_FILTER_STORAGE_KEY,
                JSON.stringify({ status, limit })
            );
        } catch {
            // Private browsing and disabled storage should not break filtering.
        }
    }, [status, limit]);

    const clearFilters = () => {
        setSearchTerm('');
        setSortBy('newest');
        setStatus(DEFAULT_FILTERS.status);
        setLimit(DEFAULT_FILTERS.limit);
        setPage(1);
    };

    const deliveries = useQuery({
        queryKey: ['admin', 'deliveries', page, limit, status],
        queryFn: () =>
            apiGet(`/admin/all-deliveries?page=${page}&limit=${limit}&status=${status}`),
        enabled: paginationMode === 'paged',
    });

    const drivers = useQuery({
        queryKey: ['admin', 'available-drivers'],
        queryFn: () => apiGet('/admin/drivers?status=active&page=1&limit=100'),
        select: response =>
            (response.data || []).filter(
                driver =>
                    driver.availability_status === 'available' &&
                    driver.kyc_status === 'verified'
            ),
    });

    const refresh = () => {
        if (paginationMode === 'cursor') {
            fetchInitialCursor();
        } else {
            queryClient.invalidateQueries({ queryKey: ['admin', 'deliveries'] });
        }
        queryClient.invalidateQueries({ queryKey: ['admin', 'available-drivers'] });
    };

    const operation = useMutation({
        mutationFn: ({ endpoint, body }) => apiPost(endpoint, body),
        onSuccess: (_, variables) => {
            showToast.success(variables.message);
            refresh();
        },
        onError: error =>
            showToast.error(
                error.response?.data?.message || 'Unable to update this delivery.'
            ),
    });

    const advance = (id, endpoint, message) =>
        operation.mutate({ endpoint, body: { delivery_id: id }, message });

    const release = id => {
        setReleaseId(id);
        setReleaseReason('');
    };

    const submitRelease = () => {
        const trimmed = releaseReason.trim();
        if (!trimmed) {
            showToast.error('Please enter a reason before releasing the assignment.');
            return;
        }
        operation.mutate({
            endpoint: '/admin/release-assignment',
            body: { delivery_id: releaseId, reason: trimmed },
            message: 'Assignment released and returned to the available driver queue.',
        });
        setReleaseId(null);
        setReleaseReason('');
    };

    const assign = id => {
        const driver_user_id = Number(manualDriver[id] || 0);
        if (!driver_user_id) {
            return showToast.error('Select an available verified driver first.');
        }
        operation.mutate({
            endpoint: '/admin/manual-assign',
            body: { delivery_id: id, driver_user_id },
            message: 'Delivery manually assigned and locked.',
        });
    };

    const baseRows = paginationMode === 'cursor' ? cursorItems : (deliveries.data?.data || []);

    const filteredRows = baseRows.filter(item => {
        if (!searchTerm.trim()) return true;
        const q = searchTerm.toLowerCase();
        return (
            String(item.id).includes(q) ||
            String(item.tracking_number || '').toLowerCase().includes(q) ||
            String(item.client_name || '').toLowerCase().includes(q) ||
            String(item.client_phone || '').includes(q) ||
            String(item.delivery_person_name || '').toLowerCase().includes(q) ||
            String(item.pickup_address || '').toLowerCase().includes(q) ||
            String(item.delivery_address || '').toLowerCase().includes(q) ||
            String(item.item_description || '').toLowerCase().includes(q)
        );
    });

    const rows = [...filteredRows].sort((a, b) => {
        if (sortBy === 'oldest') return a.id - b.id;
        if (sortBy === 'cost_desc') return Number(b.total_cost || 0) - Number(a.total_cost || 0);
        if (sortBy === 'cost_asc') return Number(a.total_cost || 0) - Number(b.total_cost || 0);
        if (sortBy === 'status') return String(a.status).localeCompare(String(b.status));
        return b.id - a.id;
    });
    const isTableLoading = paginationMode === 'cursor' ? (isLoadingCursor && cursorItems.length === 0) : deliveries.isLoading;
    const isBusy = id =>
        operation.isPending && operation.variables?.body?.delivery_id === id;

    const selector = id => (
        <div className="d-grid gap-1">
            <select
                className="form-select form-select-sm"
                value={manualDriver[id] || ''}
                onChange={e => setManualDriver({ ...manualDriver, [id]: e.target.value })}
            >
                <option value="">Manual dispatch…</option>
                {options.map(driver => (
                    <option key={driver.user_id} value={driver.user_id}>
                        {driver.full_name}
                    </option>
                ))}
            </select>
            <button
                className="btn btn-sm btn-outline-primary"
                disabled={isBusy(id)}
                onClick={() => assign(id)}
            >
                Assign selected
            </button>
        </div>
    );

    const renderOperationsAction = (delivery) => {
        if (delivery.status === 'pending') {
            return (
                <button
                    className="btn btn-sm btn-primary"
                    disabled={isBusy(delivery.id)}
                    onClick={() =>
                        advance(
                            delivery.id,
                            '/admin/review-delivery',
                            'Delivery request moved to operations review.'
                        )
                    }
                >
                    {isBusy(delivery.id) ? 'Reviewing…' : 'Begin Review'}
                </button>
            );
        }
        if (delivery.status === 'under_review') {
            return (
                <div className="d-grid gap-1">
                    <button
                        className="btn btn-sm btn-primary"
                        disabled={isBusy(delivery.id)}
                        onClick={() =>
                            advance(
                                delivery.id,
                                '/admin/broadcast-offer',
                                'Delivery offer broadcast to eligible Kano drivers.'
                            )
                        }
                    >
                        {isBusy(delivery.id) ? 'Broadcasting…' : 'Broadcast to Drivers'}
                    </button>
                    {selector(delivery.id)}
                </div>
            );
        }
        if (delivery.status === 'broadcasted') {
            return selector(delivery.id);
        }
        if (delivery.status === 'delivered') {
            return (
                <button
                    className="btn btn-sm btn-success"
                    disabled={isBusy(delivery.id)}
                    onClick={() =>
                        advance(
                            delivery.id,
                            '/admin/complete-delivery',
                            'Delivery record marked completed.'
                        )
                    }
                >
                    Complete Record
                </button>
            );
        }
        if (['assigned', 'driver_en_route'].includes(delivery.status)) {
            if (releaseId === delivery.id) {
                return (
                    <div style={{ minWidth: '200px' }}>
                        <textarea
                            className="form-control form-control-sm mb-1"
                            rows={2}
                            placeholder="Reason for releasing assignment…"
                            value={releaseReason}
                            onChange={e => setReleaseReason(e.target.value)}
                            autoFocus
                        />
                        <div className="d-flex gap-1">
                            <button
                                className="btn btn-sm btn-warning flex-grow-1"
                                disabled={operation.isPending || !releaseReason.trim()}
                                onClick={submitRelease}
                            >
                                {operation.isPending ? 'Releasing…' : 'Confirm'}
                            </button>
                            <button
                                className="btn btn-sm btn-outline-secondary"
                                onClick={() => { setReleaseId(null); setReleaseReason(''); }}
                            >
                                ✕
                            </button>
                        </div>
                    </div>
                );
            }
            return (
                <button
                    className="btn btn-sm btn-outline-warning"
                    disabled={isBusy(delivery.id)}
                    onClick={() => release(delivery.id)}
                >
                    {isBusy(delivery.id) ? 'Releasing…' : 'Release & Reassign'}
                </button>
            );
        }
        return (
            <small className="text-muted">
                {delivery.delivery_person_name ? 'Assignment locked' : 'Awaiting driver'}
            </small>
        );
    };

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
                <div>
                    <h4 className="fw-bold mb-0">System-wide Order Monitoring</h4>
                    <small className="text-muted">Real-time status tracking, dispatch, and settlement control.</small>
                </div>
                <div className="d-flex flex-wrap align-items-center gap-2">
                    <div className="input-group input-group-sm" style={{ minWidth: 220 }}>
                        <span className="input-group-text bg-white">🔍</span>
                        <input
                            type="search"
                            className="form-control"
                            placeholder="Search tracking, client, driver, route…"
                            value={searchTerm}
                            onChange={e => setSearchTerm(e.target.value)}
                        />
                    </div>

                    <select
                        className="form-select form-select-sm"
                        style={{ width: 'auto' }}
                        value={sortBy}
                        onChange={e => setSortBy(e.target.value)}
                    >
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="cost_desc">Cost: High → Low</option>
                        <option value="cost_asc">Cost: Low → High</option>
                        <option value="status">Status</option>
                    </select>

                    <select
                        id="delivery-lifecycle-filter"
                        className="form-select form-select-sm"
                        style={{ width: 'auto' }}
                        value={status}
                        onChange={e => {
                            setStatus(e.target.value);
                            setPage(1);
                        }}
                    >
                        <option value="all">All deliveries</option>
                        <optgroup label="Intake & dispatch">
                            {['pending', 'under_review', 'broadcasted'].map(value => (
                                <option value={value} key={value}>{value.replaceAll('_', ' ')}</option>
                            ))}
                        </optgroup>
                        <optgroup label="In progress">
                            {['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived'].map(value => (
                                <option value={value} key={value}>{value.replaceAll('_', ' ')}</option>
                            ))}
                        </optgroup>
                        <optgroup label="Finished">
                            {['delivered', 'completed'].map(value => (
                                <option value={value} key={value}>{value.replaceAll('_', ' ')}</option>
                            ))}
                        </optgroup>
                        <optgroup label="Exceptions">
                            {['cancelled', 'rejected', 'failed'].map(value => (
                                <option value={value} key={value}>{value.replaceAll('_', ' ')}</option>
                            ))}
                        </optgroup>
                    </select>

                    <button
                        type="button"
                        className="btn btn-sm btn-outline-secondary"
                        onClick={clearFilters}
                        disabled={!searchTerm && sortBy === 'newest' && status === DEFAULT_FILTERS.status && limit === DEFAULT_FILTERS.limit}
                    >
                        Clear filters
                    </button>

                    <div className="btn-group btn-group-sm" role="group" aria-label="Pagination Mode">
                        <button
                            type="button"
                            className={`btn ${paginationMode === 'cursor' ? 'btn-primary' : 'btn-outline-secondary'}`}
                            onClick={() => setPaginationMode('cursor')}
                            title="O(1) keyset index seek without offset scanning"
                        >
                            ⚡ Keyset
                        </button>
                        <button
                            type="button"
                            className={`btn ${paginationMode === 'paged' ? 'btn-primary' : 'btn-outline-secondary'}`}
                            onClick={() => setPaginationMode('paged')}
                            title="Classic numbered pages"
                        >
                            📄 Paged
                        </button>
                    </div>
                </div>
            </div>

            {isTableLoading ? (
                <TableSkeleton rows={5} cols={8} />
            ) : deliveries.isError && !deliveries.data && paginationMode === 'paged' ? (
                <QueryState query={deliveries} loading={<TableSkeleton rows={5} cols={8} />} />
            ) : rows.length === 0 ? (
                <div className="dashboard-query-state" role="status">
                    <strong>No deliveries match this lifecycle filter</strong>
                    <p>Clear the filter or refresh the panel to check for new operational work.</p>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={clearFilters}>
                        Clear filters
                    </button>
                </div>
            ) : (
                <>
                    <div className="table-responsive table-to-cards-wrap">
                        <table className="table table-hover align-middle table-to-cards">
                            <thead className="table-light">
                                <tr>
                                    <th>Tracking & Order</th>
                                    <th>Customer</th>
                                    <th>Route & Distance</th>
                                    <th>Driver</th>
                                    <th>Status & ETA</th>
                                    <th>Payment & Cost</th>
                                    <th>Operations</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map(delivery => (
                                    <tr key={delivery.id}>
                                        <td data-label="Tracking & Order">
                                            <div className="d-flex align-items-center gap-1">
                                                <span className="fw-bold text-dark">#{delivery.id}</span>
                                                <span className="badge bg-light text-dark border" style={{ fontSize: '0.68rem' }}>
                                                    {delivery.service_type || 'standard'}
                                                </span>
                                            </div>
                                            {delivery.tracking_number && (
                                                <small
                                                    className="d-block text-muted font-monospace"
                                                    style={{ cursor: 'pointer' }}
                                                    title="Click to copy tracking ID"
                                                    onClick={() => {
                                                        navigator.clipboard?.writeText(delivery.tracking_number);
                                                        showToast.success(`Copied: ${delivery.tracking_number}`);
                                                    }}
                                                >
                                                    📋 {delivery.tracking_number}
                                                </small>
                                            )}
                                        </td>
                                        <td data-label="Customer">
                                            <div className="fw-semibold">{delivery.client_name}</div>
                                            <small className="text-muted">
                                                <SensitiveValue value={delivery.client_phone} kind="phone" label="client phone" />
                                            </small>
                                        </td>
                                        <td data-label="Route & Distance">
                                            <div className="small text-truncate" style={{ maxWidth: 200 }} title={delivery.pickup_address}>
                                                📍 {delivery.pickup_address}
                                            </div>
                                            <div className="small text-truncate text-muted" style={{ maxWidth: 200 }} title={delivery.delivery_address}>
                                                🏁 {delivery.delivery_address}
                                            </div>
                                            {delivery.distance_km && (
                                                <span className="badge bg-secondary-subtle text-dark border" style={{ fontSize: '0.65rem' }}>
                                                    {Number(delivery.distance_km).toFixed(1)} km
                                                </span>
                                            )}
                                        </td>
                                        <td data-label="Driver">
                                            {delivery.delivery_person_name ? (
                                                <>
                                                    <div className="fw-semibold">{delivery.delivery_person_name}</div>
                                                    <small className="text-muted">
                                                        <SensitiveValue value={delivery.delivery_person_phone} kind="phone" label="partner phone" />
                                                    </small>
                                                </>
                                            ) : (
                                                <span className="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                                    ⚠️ Unassigned
                                                </span>
                                            )}
                                        </td>
                                        <td data-label="Status & ETA">
                                            <div className="d-flex flex-column gap-1">
                                                <span className={`status-pill status-${delivery.status}`}>
                                                    {delivery.status.replaceAll('_', ' ')}
                                                </span>
                                                <small className="text-muted" style={{ fontSize: '0.72rem' }}>
                                                    {(() => {
                                                        if (['delivered', 'completed'].includes(delivery.status)) return 'Completed';
                                                        if (['cancelled', 'rejected', 'failed'].includes(delivery.status)) return 'Closed';
                                                        if (delivery.status === 'arrived') return 'At destination';
                                                        if (delivery.status === 'in_transit') return `ETA: ~${Math.max(10, Math.round(Number(delivery.distance_km || 5) * 3))} min`;
                                                        if (delivery.status === 'picked_up') return 'En route to dropoff';
                                                        if (delivery.status === 'driver_en_route') return 'En route to pickup';
                                                        return 'Dispatch pending';
                                                    })()}
                                                </small>
                                            </div>
                                        </td>
                                        <td data-label="Payment & Cost">
                                            <div className="fw-bold text-dark">
                                                ₦{Number(delivery.total_cost || 0).toLocaleString('en-NG', { minimumFractionDigits: 2 })}
                                            </div>
                                            <span
                                                className={`badge ${delivery.payment_status === 'paid' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'}`}
                                                style={{ fontSize: '0.68rem' }}
                                            >
                                                {delivery.payment_status === 'paid' ? 'Paid' : 'Pending Payment'}
                                            </span>
                                        </td>
                                        <td data-label="Operations">
                                            {renderOperationsAction(delivery)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {paginationMode === 'cursor' ? (
                        <CursorPagination
                            hasMore={hasMore}
                            isLoadingMore={isLoadingMore}
                            onLoadMore={loadMoreCursor}
                            loadedCount={cursorItems.length}
                            itemLabel="deliveries"
                            buttonText="Load More Deliveries (Keyset Seek)"
                        />
                    ) : (
                        <Pagination
                            pagination={deliveries.data?.pagination || null}
                            onPageChange={setPage}
                            onLimitChange={next => {
                                setLimit(next);
                                setPage(1);
                            }}
                        />
                    )}
                </>
            )}
        </div>
    );
};

export default DeliveriesTab;

