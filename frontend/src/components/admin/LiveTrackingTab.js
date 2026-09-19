import { useEffect, useRef, useState } from 'react';
import { useInfiniteQuery } from '@tanstack/react-query';
import { apiGet } from '../../api/client';
import LiveLocationMap from '../common/LiveLocationMap';
import Icon from '../common/Icon';
import QueryState from '../common/QueryState';

const formatGpsTime = value => {
    if (!value) return 'No update received';
    const timestamp = new Date(value);
    return Number.isNaN(timestamp.getTime())
        ? 'Unknown update time'
        : timestamp.toLocaleString([], { dateStyle: 'short', timeStyle: 'short' });
};

const gpsState = item => {
    if (!item.recorded_at) {
        return { label: 'GPS offline', className: 'text-danger', icon: '○' };
    }
    if (!item.is_live) {
        return { label: 'GPS stale', className: 'text-warning', icon: '!' };
    }
    return { label: 'GPS live', className: 'text-success', icon: '●' };
};

const accuracyLabel = value => {
    const accuracy = Number(value);
    return Number.isFinite(accuracy) && accuracy > 0 ? `±${Math.round(accuracy)} m` : 'Accuracy unavailable';
};

const LiveTrackingTab = () => {
    const [selectedId, setSelectedId] = useState(null);
    const loadMoreRef = useRef(null);

    const locations = useInfiniteQuery({
        queryKey: ['admin', 'live-tracking'],
        initialPageParam: null,
        queryFn: ({ pageParam }) =>
            apiGet(`/admin/live-tracking?limit=50${pageParam ? `&cursor=${pageParam}` : ''}`),
        getNextPageParam: lastPage => lastPage.data?.next_cursor ?? undefined,
        refetchInterval: 15_000,
    });

    const items = locations.data?.pages.flatMap(page => page.data?.items || []) || [];

    useEffect(() => {
        const target = loadMoreRef.current;
        if (!target || !locations.hasNextPage) return undefined;

        const observer = new IntersectionObserver(
            entries => {
                if (entries[0].isIntersecting && !locations.isFetchingNextPage) {
                    locations.fetchNextPage();
                }
            },
            { rootMargin: '240px' }
        );
        observer.observe(target);
        return () => observer.disconnect();
    }, [locations.hasNextPage, locations.isFetchingNextPage, locations.fetchNextPage]);

    useEffect(() => {
        if (!items.some(item => item.delivery_id === selectedId)) {
            setSelectedId(items[0]?.delivery_id || null);
        }
    }, [items, selectedId]);

    const selected = items.find(item => item.delivery_id === selectedId) || null;

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4 className="fw-bold mb-1">Live Operations Map</h4>
                    <p className="text-muted mb-0">
                        Active Kano deliveries only. A location becomes stale after two minutes.
                    </p>
                </div>
                <button
                    className="btn btn-sm btn-outline-primary"
                    onClick={() => locations.refetch()}
                >
                    <Icon name="refresh" size={16} /> Refresh
                </button>
            </div>

            <div className="row g-3">
                <div className="col-md-5">
                    <div className="list-group">
                        {locations.isLoading ? (
                            <div className="text-muted p-3">Loading locations…</div>
                        ) : locations.isError && !locations.data ? (
                            <QueryState query={locations} loading={null} />
                        ) : items.length ? (
                            <>
                                {items.map(item => (
                                    (() => {
                                        const state = gpsState(item);
                                        const isActive = selectedId === item.delivery_id;
                                        return (
                                            <button
                                                key={item.delivery_id}
                                                onClick={() => setSelectedId(item.delivery_id)}
                                                className={`list-group-item list-group-item-action p-3 mb-2 border rounded-2 ${
                                                    isActive ? 'active shadow-sm' : ''
                                                }`}
                                            >
                                                <div className="d-flex justify-content-between align-items-center mb-1">
                                                    <strong>
                                                        {item.tracking_number || `#${item.delivery_id}`}
                                                    </strong>
                                                    <span className={`badge ${isActive ? 'bg-white text-dark' : 'bg-primary-subtle text-primary border'}`} style={{ fontSize: '0.68rem' }}>
                                                        {item.status.replaceAll('_', ' ')}
                                                    </span>
                                                </div>

                                                {(item.pickup_address || item.delivery_address) && (
                                                    <div className={`small mb-1 text-truncate ${isActive ? 'text-white-50' : 'text-muted'}`} style={{ fontSize: '0.75rem' }}>
                                                        📍 {item.pickup_address || 'Pickup'} → 🏁 {item.delivery_address || 'Delivery'}
                                                    </div>
                                                )}

                                                <div className="d-flex justify-content-between align-items-center mt-2">
                                                    <small className={`fw-semibold ${isActive ? 'text-white' : ''}`}>
                                                        🛵 {item.driver_name || 'Driver pending'}
                                                    </small>
                                                    <span className={`badge ${isActive ? 'bg-white text-dark' : 'bg-success-subtle text-success border border-success-subtle'}`} style={{ fontSize: '0.68rem' }}>
                                                        {item.status === 'in_transit' ? '⏱️ ETA ~15 min' : 'Active'}
                                                    </span>
                                                </div>

                                                <div className={`small mt-1 ${isActive ? 'text-white-50' : state.className}`} role="status">
                                                    {state.icon} {state.label} · Last update: {formatGpsTime(item.recorded_at)}
                                                </div>
                                            </button>
                                        );
                                    })()
                                ))}
                                <div ref={loadMoreRef} className="small text-muted text-center p-2">
                                    {locations.isFetchingNextPage ? 'Loading more active deliveries…' : ' '}
                                </div>
                            </>
                        ) : (
                            <div className="text-muted p-3">
                                No active deliveries are currently being tracked.
                            </div>
                        )}
                    </div>
                </div>

                <div className="col-md-7">
                    <LiveLocationMap location={selected} height={380} />
                    {selected && (
                        <div className="card border p-3 mt-2 bg-light">
                            <div className="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <strong className="text-dark">Order #{selected.delivery_id}</strong>
                                    {selected.tracking_number && (
                                        <span className="ms-2 badge bg-light text-dark border font-monospace">
                                            {selected.tracking_number}
                                        </span>
                                    )}
                                </div>
                                <span className="badge bg-primary text-uppercase" style={{ fontSize: '0.68rem' }}>
                                    {selected.status.replaceAll('_', ' ')}
                                </span>
                            </div>
                            <div className="row g-2 small text-muted">
                                <div className="col-6">
                                    <span>Dispatch Rider: <strong className="text-dark">{selected.driver_name || 'Assigned partner'}</strong></span>
                                </div>
                                <div className="col-6 text-end">
                                    <span>Telemetry: <strong className={gpsState(selected).className}>{gpsState(selected).label}</strong></span>
                                </div>
                                <div className="col-12">
                                    <span>GPS Accuracy: {accuracyLabel(selected.accuracy_m)} · Last ping: {formatGpsTime(selected.recorded_at)}</span>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default LiveTrackingTab;

