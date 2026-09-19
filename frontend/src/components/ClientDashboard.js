import React, { useState, useEffect, useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../api/client';
import { useAuth } from './AuthContext';
import ThemeToggle from './common/ThemeToggle';
import KycStatusBanner from './common/KycStatusBanner';
import VerifiedBadge from './common/VerifiedBadge';
import ClientOverviewTab from './client/ClientOverviewTab';
import BookDeliveryTab from './client/BookDeliveryTab';
import ShipmentHistoryTab from './client/ShipmentHistoryTab';
import ClientTrackingTab from './client/ClientTrackingTab';
import ClientPaymentsTab from './client/ClientPaymentsTab';
import ClientNotificationsTab from './client/ClientNotificationsTab';
import ClientProfileTab from './client/ClientProfileTab';
import ClientKycTab from './client/ClientKycTab';
import TrackingModal from './client/TrackingModal';
import NotificationBell from './client/NotificationBell';
import ErrorBoundary from './common/ErrorBoundary';
import Icon from './common/Icon';
import { queryClient } from '../api/queryClient';

const ClientDashboard = () => {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const [activeTab, setActiveTab] = useState('overview');
    const [statusFilter, setStatusFilter] = useState('all');
    const [deliveries, setDeliveries] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(10);
    const [mobileNavOpen, setMobileNavOpen] = useState(false);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [selectedTrackDelivery, setSelectedTrackDelivery] = useState(null);

    const kycQuery = useQuery({
        queryKey: ['client', 'kyc-status'],
        queryFn: async () => (await apiClient.get('/client/kyc/status')).data.data,
        enabled: Boolean(user?.role === 'client'),
    });
    const kyc = kycQuery.data || null;
    const kycLoading = kycQuery.isLoading;

    useEffect(() => {
        if (!mobileNavOpen) return undefined;
        const onKeyDown = (event) => {
            if (event.key === 'Escape') setMobileNavOpen(false);
        };
        document.body.classList.add('mobile-menu-open');
        window.addEventListener('keydown', onKeyDown);
        return () => {
            document.body.classList.remove('mobile-menu-open');
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [mobileNavOpen]);

    const [paginationMode, setPaginationMode] = useState('cursor');
    const [nextCursor, setNextCursor] = useState(null);
    const [hasMore, setHasMore] = useState(false);
    const [isLoadingMore, setIsLoadingMore] = useState(false);

    const isKycVerified = kyc?.kyc_status === 'verified';

    const fetchDeliveries = useCallback(async (currentPage = 1, currentLimit = 10) => {
        setHistoryLoading(true);
        try {
            if (paginationMode === 'cursor') {
                const res = await apiClient.get(`/deliveries/my-deliveries?limit=${currentLimit}&cursor=`);
                const items = res.data?.data?.deliveries || res.data?.data || [];
                setDeliveries(items);
                setNextCursor(res.data?.data?.next_cursor ?? null);
                setHasMore(Boolean(res.data?.data?.has_more));
            } else {
                const res = await apiClient.get(`/deliveries/my-deliveries?page=${currentPage}&limit=${currentLimit}`);
                setDeliveries(res.data?.data || []);
                setPagination(res.data?.pagination || null);
            }
        } catch (err) {
            console.error('Failed to fetch deliveries', err);
        } finally {
            setHistoryLoading(false);
        }
    }, [paginationMode]);

    useEffect(() => {
        if (!user || user.role !== 'client') {
            navigate('/login');
            return;
        }
        fetchDeliveries(page, limit);
    }, [activeTab, page, limit, user, navigate, fetchDeliveries]);

    const loadMoreDeliveries = async () => {
        if (!nextCursor || isLoadingMore) return;
        setIsLoadingMore(true);
        try {
            const res = await apiClient.get(`/deliveries/my-deliveries?limit=${limit}&cursor=${nextCursor}`);
            const newItems = res.data?.data?.deliveries || res.data?.data || [];
            setDeliveries(prev => [...prev, ...newItems]);
            setNextCursor(res.data?.data?.next_cursor ?? null);
            setHasMore(Boolean(res.data?.data?.has_more));
        } catch (err) {
            console.error('Failed to load more deliveries', err);
        } finally {
            setIsLoadingMore(false);
        }
    };

    const handleTrackDelivery = async (item) => {
        if (!item) return;
        if (item.pickup_address && item.status) {
            setSelectedTrackDelivery(item);
            return;
        }
        const existing = deliveries.find(d => d.id === item.id || (item.tracking_number && d.tracking_number === item.tracking_number));
        if (existing) {
            setSelectedTrackDelivery(existing);
            return;
        }
        try {
            const res = await apiClient.get(`/deliveries/my-deliveries?limit=50`);
            const items = res.data?.data?.deliveries || res.data?.data || [];
            const match = items.find(d => d.id === item.id || (item.tracking_number && d.tracking_number === item.tracking_number));
            setSelectedTrackDelivery(match || item);
        } catch {
            setSelectedTrackDelivery(item);
        }
    };

    const handleLogout = () => {
        queryClient.clear();
        logout();
        navigate('/');
    };

    // Calculate active shipments count for sidebar badge
    const activeShipmentsCount = useMemo(() => {
        return deliveries.filter(d =>
            ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived'].includes(d.status)
        ).length;
    }, [deliveries]);

    return (
        <div className="min-vh-100 bg-light d-flex flex-column dashboard-shell client-dashboard">
            {/* Header Navbar */}
            <nav className="navbar navbar-dark px-4 py-3 app-navbar dashboard-header">
                <div className="container-fluid">
                    <span className="navbar-brand fw-bold fs-4 d-flex align-items-center gap-2">
                        📦 Tweak Insight <span className="badge badge-role-client">Client Hub</span>
                    </span>
                    <div className="text-white d-flex align-items-center gap-2 gap-sm-3">
                        <span className="fw-semibold dashboard-user-name d-none d-sm-inline">
                            👤 {user?.full_name}
                        </span>
                        <NotificationBell onTrackDelivery={handleTrackDelivery} />
                        <ThemeToggle compact />
                        <button className="btn btn-sm btn-outline-light px-3" onClick={handleLogout}>
                            Logout
                        </button>
                        <button 
                            type="button" 
                            className="dashboard-menu-toggle" 
                            onClick={() => setMobileNavOpen(open => !open)} 
                            aria-controls="client-dashboard-navigation" 
                            aria-expanded={mobileNavOpen} 
                            aria-label={mobileNavOpen ? 'Close dashboard navigation' : 'Open dashboard navigation'}
                            title={mobileNavOpen ? 'Close dashboard navigation' : 'Open dashboard navigation'}
                        >
                            <Icon name="menu" />
                        </button>
                    </div>
                </div>
            </nav>

            <div className="container-fluid px-4 py-4 flex-grow-1 dashboard-content">
                <div className="row g-4">
                    {/* Sidebar Navigation */}
                    {mobileNavOpen && (
                        <button 
                            type="button" 
                            className="dashboard-nav-backdrop" 
                            aria-label="Close dashboard navigation" 
                            onClick={() => setMobileNavOpen(false)} 
                        />
                    )}
                    <aside id="client-dashboard-navigation" className={`col-lg-3 col-md-4 dashboard-sidebar dashboard-sidebar--drawer ${mobileNavOpen ? 'is-open' : ''}`}>
                        <div className="card border-0 shadow-sm custom-card p-3">
                            <div className="text-center py-3 border-bottom mb-3">
                                <div className="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style={{ width: '54px', height: '54px', fontSize: '24px' }}>
                                    {user?.full_name ? user.full_name.charAt(0).toUpperCase() : 'C'}
                                </div>
                                <h6 className="fw-bold mb-0">{user?.full_name}</h6>
                                <small className="text-muted">{user?.email}</small>
                                <div className="mt-2">
                                    <VerifiedBadge status={kyc?.kyc_status} label="KYC verified" />
                                </div>
                            </div>

                            {/* Grouped Navigation Links */}
                            <div className="d-flex flex-column gap-3">
                                {/* Group: Overview */}
                                <div>
                                    <button
                                        className={`list-group-item list-group-item-action rounded-3 d-flex align-items-center gap-2 ${activeTab === 'overview' ? 'active' : ''}`}
                                        onClick={() => { setActiveTab('overview'); setMobileNavOpen(false); }}
                                    >
                                        <span>🏠</span>
                                        <span className="fw-semibold">Overview</span>
                                    </button>
                                </div>

                                {/* Group: Deliveries */}
                                <div>
                                    <div className="text-muted text-uppercase fw-bold px-2 py-1" style={{ fontSize: '0.68rem', letterSpacing: '0.8px' }}>
                                        Deliveries
                                    </div>
                                    <div className="list-group list-group-flush gap-1">
                                        <button
                                            className={`list-group-item list-group-item-action rounded-2 d-flex justify-content-between align-items-center ${activeTab === 'book' ? 'active' : ''}`}
                                            onClick={() => { setActiveTab(isKycVerified ? 'book' : 'kyc'); setMobileNavOpen(false); }}
                                        >
                                            <span className="d-flex align-items-center gap-2">
                                                <span>📦</span> Book Delivery
                                            </span>
                                            {!isKycVerified && <span className="badge bg-warning text-dark ms-1" style={{ fontSize: '0.68rem' }}>🔒</span>}
                                        </button>
                                        <button
                                            className={`list-group-item list-group-item-action rounded-2 d-flex justify-content-between align-items-center ${activeTab === 'history' ? 'active' : ''}`}
                                            onClick={() => { setStatusFilter('all'); setActiveTab('history'); setMobileNavOpen(false); }}
                                        >
                                            <span className="d-flex align-items-center gap-2">
                                                <span>📋</span> My Shipments
                                            </span>
                                            {activeShipmentsCount > 0 && (
                                                <span className="badge rounded-pill bg-primary px-2" style={{ fontSize: '0.68rem' }}>
                                                    {activeShipmentsCount}
                                                </span>
                                            )}
                                        </button>
                                        <button
                                            className={`list-group-item list-group-item-action rounded-2 d-flex align-items-center gap-2 ${activeTab === 'tracking' ? 'active' : ''}`}
                                            onClick={() => { setActiveTab('tracking'); setMobileNavOpen(false); }}
                                        >
                                            <span>🗺️</span> Track Shipment
                                        </button>
                                    </div>
                                </div>

                                {/* Group: Finance */}
                                <div>
                                    <div className="text-muted text-uppercase fw-bold px-2 py-1" style={{ fontSize: '0.68rem', letterSpacing: '0.8px' }}>
                                        Finance
                                    </div>
                                    <div className="list-group list-group-flush">
                                        <button
                                            className={`list-group-item list-group-item-action rounded-2 d-flex align-items-center gap-2 ${activeTab === 'payments' ? 'active' : ''}`}
                                            onClick={() => { setActiveTab('payments'); setMobileNavOpen(false); }}
                                        >
                                            <span>💳</span> Payments & Receipts
                                        </button>
                                    </div>
                                </div>

                                {/* Group: Account & Activity */}
                                <div>
                                    <div className="text-muted text-uppercase fw-bold px-2 py-1" style={{ fontSize: '0.68rem', letterSpacing: '0.8px' }}>
                                        Account & Activity
                                    </div>
                                    <div className="list-group list-group-flush gap-1">
                                        <button
                                            className={`list-group-item list-group-item-action rounded-2 d-flex align-items-center gap-2 ${activeTab === 'notifications' ? 'active' : ''}`}
                                            onClick={() => { setActiveTab('notifications'); setMobileNavOpen(false); }}
                                        >
                                            <span>🔔</span> Notifications
                                        </button>
                                        <button 
                                            className={`list-group-item list-group-item-action rounded-2 d-flex align-items-center gap-2 ${activeTab === 'profile' ? 'active' : ''}`} 
                                            onClick={() => { setActiveTab('profile'); setMobileNavOpen(false); }}
                                        >
                                            <span>👤</span> Profile & Settings
                                        </button>
                                        <button 
                                            className={`list-group-item list-group-item-action rounded-2 d-flex justify-content-between align-items-center ${activeTab === 'kyc' ? 'active' : ''}`} 
                                            onClick={() => { setActiveTab('kyc'); setMobileNavOpen(false); }}
                                        >
                                            <span className="d-flex align-items-center gap-2">
                                                <span>🪪</span> Identity Verification
                                            </span>
                                            {!isKycVerified && <span className="badge bg-warning text-dark ms-1" style={{ fontSize: '0.68rem' }}>Required</span>}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </aside>

                    {/* Main Content Area */}
                    <main className="col-lg-9 col-md-8 dashboard-main">
                        <KycStatusBanner 
                            status={kyc?.kyc_status} 
                            rejectionReason={kyc?.kyc_rejection_reason} 
                            onAction={() => setActiveTab('kyc')} 
                        />
                        
                        <ErrorBoundary
                            resetKey={activeTab}
                            fallbackTitle="Client Panel Unavailable"
                            fallbackMessage="An unexpected error occurred while loading this tab. Your active bookings and account details remain safe."
                        >
                            {activeTab === 'overview' && (
                                <ClientOverviewTab
                                    user={user}
                                    deliveries={deliveries}
                                    loading={historyLoading}
                                    onBook={() => setActiveTab(isKycVerified ? 'book' : 'kyc')}
                                    onViewAll={() => { setStatusFilter('all'); setActiveTab('history'); }}
                                    onTrack={handleTrackDelivery}
                                    onSelectStatusFilter={(filter) => { setStatusFilter(filter); setActiveTab('history'); }}
                                />
                            )}

                            {activeTab === 'book' && (
                                isKycVerified ? (
                                    <BookDeliveryTab onCreated={() => { fetchDeliveries(1, limit); setActiveTab('overview'); }} />
                                ) : (
                                    <div className="card border-0 shadow-sm custom-card p-4">
                                        <h4 className="fw-bold">Complete identity verification first</h4>
                                        <p className="text-muted mb-3">KYC verification is required before you can create a delivery request.</p>
                                        <button className="btn btn-primary" onClick={() => setActiveTab('kyc')}>
                                            Open identity verification
                                        </button>
                                    </div>
                                )
                            )}

                            {activeTab === 'history' && (
                                <ShipmentHistoryTab 
                                    deliveries={deliveries} 
                                    loading={historyLoading}
                                    pagination={pagination}
                                    paginationMode={paginationMode}
                                    onTogglePaginationMode={setPaginationMode}
                                    hasMore={hasMore}
                                    isLoadingMore={isLoadingMore}
                                    onLoadMore={loadMoreDeliveries}
                                    onPageChange={(p) => setPage(p)}
                                    onLimitChange={(l) => { setLimit(l); setPage(1); }}
                                    onRefresh={() => fetchDeliveries(page, limit)}
                                    onTrack={handleTrackDelivery}
                                    onStart={() => setActiveTab(isKycVerified ? 'book' : 'kyc')}
                                    initialStatusFilter={statusFilter}
                                    onViewReceipt={(item) => handleTrackDelivery(item)}
                                />
                            )}

                            {activeTab === 'tracking' && (
                                <ClientTrackingTab
                                    deliveries={deliveries}
                                    selectedDelivery={selectedTrackDelivery}
                                    onSelectDelivery={setSelectedTrackDelivery}
                                    onBook={() => setActiveTab(isKycVerified ? 'book' : 'kyc')}
                                />
                            )}

                            {activeTab === 'payments' && (
                                <ClientPaymentsTab
                                    user={user}
                                    deliveries={deliveries}
                                    loading={historyLoading}
                                    onBook={() => setActiveTab(isKycVerified ? 'book' : 'kyc')}
                                />
                            )}

                            {activeTab === 'notifications' && (
                                <ClientNotificationsTab
                                    onTrackDelivery={handleTrackDelivery}
                                    onRefreshCount={() => {}}
                                />
                            )}

                            {activeTab === 'profile' && (
                                <ClientProfileTab 
                                    user={user}
                                    kyc={kyc}
                                    onOpenKyc={() => setActiveTab('kyc')}
                                />
                            )}

                            {activeTab === 'kyc' && (
                                <ClientKycTab 
                                    kyc={kyc} 
                                    loading={kycLoading} 
                                    onRefresh={() => kycQuery.refetch()} 
                                />
                            )}
                        </ErrorBoundary>
                    </main>
                </div>
            </div>

            {/* Tracking Modal with Error Boundary */}
            {selectedTrackDelivery && (
                <ErrorBoundary
                    fallbackTitle="Live Tracking Map Error"
                    fallbackMessage="The map component encountered an issue. Close and re-open to retry."
                    secondaryAction={{ label: 'Close Map', onClick: () => setSelectedTrackDelivery(null) }}
                >
                    <TrackingModal 
                        delivery={selectedTrackDelivery} 
                        onClose={() => setSelectedTrackDelivery(null)} 
                    />
                </ErrorBoundary>
            )}
        </div>
    );
};

export default ClientDashboard;
