import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from './AuthContext';
import ThemeToggle from './common/ThemeToggle';
import { apiGet } from '../api/client';
import { queryClient } from '../api/queryClient';
import OverviewTab from './admin/OverviewTab';
import ApprovalsTab from './admin/ApprovalsTab';
import DocumentReviewTab from './admin/DocumentReviewTab';
import { BusinessAccountsTab, FleetTab, RateCardsTab, UsersTab } from './admin/OperationsTabs';
import DeliveriesTab from './admin/DeliveriesTab';
import AuditTab from './admin/AuditTab';
import LiveTrackingTab from './admin/LiveTrackingTab';
import ClientKycTab from './admin/ClientKycTab';
import ErrorBoundary from './common/ErrorBoundary';
import Icon from './common/Icon';
import ReportsTab from './admin/ReportsTab';
import ExceptionsAlertsTab from './admin/ExceptionsAlertsTab';
import FinanceOverviewTab from './admin/FinanceOverviewTab';

// Kept for mobile horizontal quick-tabs (all 12 entries, order unchanged)
const tabs = [
    ['overview',         '📊 Overview'],
    ['deliveries',       '📦 Order Monitoring'],
    ['live-tracking',    '📍 Live Operations Map'],
    ['exceptions',       '⚠️ Exceptions & Alerts'],
    ['approvals',        '⌛ Pending Approvals'],
    ['document-review',  '📄 Document Review'],
    ['fleet',            '🚚 Fleet & Drivers'],
    ['users',            '👥 User Management'],
    ['client-kyc',       '👤 Client KYC'],
    ['finance-overview', '💰 Financial Overview'],
    ['rates',            '₦ Rate Cards'],
    ['business',         '🏢 Business Accounts'],
    ['reports',          '📈 Analytics & Reports'],
    ['audit',            '🧾 Operations Audit'],
];

// ── Sidebar grouped structure ─────────────────────────────────────────────
// Top-level items are always visible; groups below are collapsible.
const NAV_TOP = [
    { id: 'overview',      icon: '📊', label: 'Overview' },
    { id: 'deliveries',    icon: '📦', label: 'Order Monitoring', badge: 'deliveries' },
    { id: 'live-tracking', icon: '📍', label: 'Live Operations Map' },
    { id: 'exceptions',    icon: '⚠️', label: 'Exceptions & Alerts', badge: 'exceptions' },
];

const NAV_GROUPS = [
    {
        key: 'operations',
        label: 'Operations',
        icon: '⚙️',
        items: [
            { id: 'approvals',       icon: '⌛', label: 'Pending Approvals', badge: 'approvals' },
            { id: 'document-review', icon: '📄', label: 'Document Review' },
            { id: 'fleet',           icon: '🚚', label: 'Fleet & Drivers' },
        ],
    },
    {
        key: 'users',
        label: 'Users & KYC',
        icon: '👥',
        items: [
            { id: 'users',      icon: '👥', label: 'User Management' },
            { id: 'client-kyc', icon: '👤', label: 'Client KYC', badge: 'clientKyc' },
        ],
    },
    {
        key: 'finance',
        label: 'Finance',
        icon: '₦',
        items: [
            { id: 'finance-overview', icon: '💰', label: 'Financial Overview' },
            { id: 'rates',            icon: '₦',  label: 'Rate Cards' },
            { id: 'business',         icon: '🏢', label: 'Business Accounts' },
        ],
    },
    {
        key: 'reports',
        label: 'Reports & Audit',
        icon: '📋',
        items: [
            { id: 'reports', icon: '📈', label: 'Analytics & Reports' },
            { id: 'audit',   icon: '🧾', label: 'Operations Audit Log' },
        ],
    },
];

const AdminDashboard = () => {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const [activeTab, setActiveTab] = useState('overview');
    const [mobileNavOpen, setMobileNavOpen] = useState(false);

    // Which group keys are open. Operations starts open (highest-frequency items).
    const [openGroups, setOpenGroups] = useState(() => {
        const initial = { operations: true };
        for (const group of NAV_GROUPS) {
            if (group.items.some(item => item.id === 'overview')) {
                initial[group.key] = true;
            }
        }
        return initial;
    });

    const toggleGroup = (key) =>
        setOpenGroups(prev => ({ ...prev, [key]: !prev[key] }));

    const goToTab = (id) => {
        setActiveTab(id);
        setMobileNavOpen(false);
        // Auto-expand whichever group contains this tab (if any)
        for (const group of NAV_GROUPS) {
            if (group.items.some(item => item.id === id)) {
                setOpenGroups(prev => ({ ...prev, [group.key]: true }));
                break;
            }
        }
    };

    const stats = useQuery({
        queryKey: ['admin', 'dashboard-stats'],
        queryFn: () => apiGet('/admin/dashboard-stats').then(result => result.data),
    });

    const handleLogout = () => {
        queryClient.clear();
        logout();
        navigate('/');
    };

    const content = {
        'overview':         <OverviewTab onNavigate={goToTab} />,
        'deliveries':       <DeliveriesTab />,
        'live-tracking':    <LiveTrackingTab />,
        'exceptions':       <ExceptionsAlertsTab />,
        'approvals':        <ApprovalsTab />,
        'document-review':  <DocumentReviewTab />,
        'fleet':            <FleetTab />,
        'users':            <UsersTab />,
        'client-kyc':       <ClientKycTab />,
        'finance-overview': <FinanceOverviewTab onNavigate={goToTab} />,
        'rates':            <RateCardsTab />,
        'business':         <BusinessAccountsTab />,
        'reports':          <ReportsTab />,
        'audit':            <AuditTab />,
    };

    // Close mobile nav on Escape key
    useEffect(() => {
        if (!mobileNavOpen) return undefined;

        const onKeyDown = event => {
            if (event.key === 'Escape') setMobileNavOpen(false);
        };

        document.body.classList.add('mobile-menu-open');
        window.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.classList.remove('mobile-menu-open');
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [mobileNavOpen]);

    const pendingApprovals = stats.data?.users?.pending_approvals ?? 0;
    const activeShipments = stats.data?.active_shipments ?? stats.data?.in_transit ?? 0;
    const pendingReview = stats.data?.pending_review ?? 0;
    const delayedShipments = stats.data?.delayed_shipments ?? 0;
    const availableDrivers = stats.data?.fleet?.available ?? stats.data?.fleet?.active ?? 0;
    const totalVolume = stats.data?.total_volume ? Math.round(stats.data.total_volume).toLocaleString() : '0';

    return (
        <div className="min-vh-100 bg-light d-flex flex-column dashboard-shell admin-dashboard">

            {/* ── Top Navbar ──────────────────────────────────────────── */}
            <nav className="navbar navbar-dark px-4 py-3 app-navbar dashboard-header">
                <div className="container-fluid">
                    <span className="navbar-brand fw-bold fs-4 d-flex align-items-center gap-2">
                        🛡️ Tweak Insight
                        <span className="badge badge-role-admin">Admin Panel</span>
                    </span>

                    <div className="text-white d-flex align-items-center gap-3">
                        <span className="fw-semibold dashboard-user-name">
                            Administrator: {user.full_name}
                        </span>

                        <ThemeToggle compact />

                        <button
                            className="btn btn-sm btn-outline-light px-3"
                            onClick={handleLogout}
                        >
                            Logout
                        </button>

                        <button
                            type="button"
                            className="dashboard-menu-toggle"
                            onClick={() => setMobileNavOpen(open => !open)}
                            aria-controls="admin-dashboard-navigation"
                            aria-expanded={mobileNavOpen}
                            aria-label={
                                mobileNavOpen ? 'Close dashboard navigation' : 'Open dashboard navigation'
                            }
                            title={mobileNavOpen ? 'Close dashboard navigation' : 'Open dashboard navigation'}
                        >
                            <Icon name="menu" />
                        </button>
                    </div>
                </div>
            </nav>

            {/* ── Live Operations Status Strip ───────────────────────── */}
            <div className="admin-status-strip">
                <div className="container-fluid px-4">
                    <div className="admin-status-strip-inner">
                        {/* Live Dispatch Pulse */}
                        <div className="status-strip-item status-strip-pulse">
                            <span className="pulse-indicator-dot"></span>
                            <span className="fw-bold">Kano Dispatch Online</span>
                        </div>

                        <div className="status-strip-divider d-none d-sm-block"></div>

                        {/* Active Dispatches */}
                        <div className="status-strip-item" title="Deliveries currently active or in transit">
                            <span className="status-strip-icon">🚚</span>
                            <span className="status-strip-label">Active Orders:</span>
                            <span className="status-strip-val text-primary fw-bold">
                                {stats.isLoading ? '…' : activeShipments}
                            </span>
                        </div>

                        <div className="status-strip-divider d-none d-md-block"></div>

                        {/* Pending Actions */}
                        <div className="status-strip-item" title="Orders pending review and partner approvals">
                            <span className="status-strip-icon">⌛</span>
                            <span className="status-strip-label">Pending Queue:</span>
                            <span className={`status-strip-val fw-bold ${pendingReview + pendingApprovals > 0 ? 'text-warning' : 'text-success'}`}>
                                {stats.isLoading ? '…' : (pendingReview + pendingApprovals)}
                            </span>
                        </div>

                        <div className="status-strip-divider d-none d-md-block"></div>

                        {/* Available Fleet */}
                        <div className="status-strip-item" title="Drivers available for assignment">
                            <span className="status-strip-icon">🛵</span>
                            <span className="status-strip-label">Fleet Ready:</span>
                            <span className="status-strip-val text-success fw-bold">
                                {stats.isLoading ? '…' : `${availableDrivers} Drivers`}
                            </span>
                        </div>

                        <div className="status-strip-divider d-none d-lg-block"></div>

                        {/* Gross Volume */}
                        <div className="status-strip-item d-none d-lg-flex" title="Total cumulative delivery volume">
                            <span className="status-strip-icon">💰</span>
                            <span className="status-strip-label">Total Volume:</span>
                            <span className="status-strip-val text-dark fw-bold">
                                {stats.isLoading ? '…' : `₦${totalVolume}`}
                            </span>
                        </div>

                        {/* Sync / Refresh Button */}
                        <button
                            type="button"
                            className="btn btn-sm status-strip-refresh ms-auto"
                            onClick={() => stats.refetch()}
                            title="Refresh Operational Metrics"
                            disabled={stats.isFetching}
                        >
                            <span className={stats.isFetching ? 'spin-animation' : ''}>🔄</span>
                            <span className="d-none d-sm-inline ms-1">{stats.isFetching ? 'Syncing…' : 'Sync'}</span>
                        </button>
                    </div>
                </div>
            </div>

            {/* ── Main Layout ─────────────────────────────────────────── */}
            <div className="container-fluid px-4 py-3 flex-grow-1 dashboard-content">

                {/* ── Horizontal Quick-Tabs for Mobile (< 992px) ───────── */}
                <div className="admin-mobile-quicktabs d-lg-none mb-3">
                    <div className="admin-quicktabs-scroll">
                        {tabs.map(([id, label]) => {
                            const isActive = activeTab === id;
                            return (
                                <button
                                    key={`quicktab-${id}`}
                                    type="button"
                                    className={`admin-quicktab-chip ${isActive ? 'active' : ''}`}
                                    onClick={() => goToTab(id)}
                                >
                                    <span>{label}</span>
                                    {id === 'approvals' && pendingApprovals > 0 && (
                                        <span className="badge bg-warning text-dark ms-1">
                                            {pendingApprovals}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div className="row g-4">

                    {/* Backdrop overlay for mobile nav */}
                    {mobileNavOpen && (
                        <button
                            type="button"
                            className="dashboard-nav-backdrop"
                            aria-label="Close dashboard navigation"
                            onClick={() => setMobileNavOpen(false)}
                        />
                    )}

                    {/* ── Sidebar ─────────────────────────────────────── */}
                    <aside
                        id="admin-dashboard-navigation"
                        className={`col-lg-3 col-md-4 dashboard-sidebar dashboard-sidebar--drawer ${
                            mobileNavOpen ? 'is-open' : ''
                        }`}
                    >
                        <div className="card border-0 shadow-sm custom-card p-3">
                            {/* Avatar + name */}
                            <div className="text-center py-3 border-bottom mb-2">
                                <div
                                    className="bg-danger text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2 fw-bold"
                                    style={{ width: '48px', height: '48px', fontSize: '22px' }}
                                >
                                    A
                                </div>
                                <h6 className="fw-bold mb-0 small">{user.full_name}</h6>
                                <small className="text-muted" style={{ fontSize: '0.72rem' }}>System Administrator</small>
                            </div>

                            {/* ── Top-level pinned items ── */}
                            <nav className="admin-sidenav">
                                <ul className="admin-sidenav__list">
                                    {NAV_TOP.map(({ id, icon, label, badge }) => (
                                        <li key={id}>
                                            <button
                                                type="button"
                                                className={`admin-sidenav__item${activeTab === id ? ' is-active' : ''}`}
                                                onClick={() => goToTab(id)}
                                            >
                                                <span className="admin-sidenav__icon" aria-hidden="true">{icon}</span>
                                                <span className="admin-sidenav__label">{label}</span>
                                                {badge === 'deliveries' && activeShipments > 0 && (
                                                    <span className="badge bg-primary text-white ms-auto" style={{ fontSize: '0.65rem' }}>
                                                        {activeShipments}
                                                    </span>
                                                )}
                                                {badge === 'exceptions' && delayedShipments > 0 && (
                                                    <span className="badge bg-danger text-white ms-auto" style={{ fontSize: '0.65rem' }}>
                                                        {delayedShipments}
                                                    </span>
                                                )}
                                            </button>
                                        </li>
                                    ))}
                                </ul>

                                {/* ── Collapsible groups ── */}
                                {NAV_GROUPS.map(group => {
                                    const isOpen = Boolean(openGroups[group.key]);
                                    const hasActiveChild = group.items.some(item => item.id === activeTab);
                                    return (
                                        <div key={group.key} className="admin-sidenav__group">
                                            <button
                                                type="button"
                                                className={`admin-sidenav__group-header${hasActiveChild ? ' has-active' : ''}`}
                                                onClick={() => toggleGroup(group.key)}
                                                aria-expanded={isOpen}
                                            >
                                                <span className="admin-sidenav__icon" aria-hidden="true">{group.icon}</span>
                                                <span className="admin-sidenav__label">{group.label}</span>
                                                <span className={`admin-sidenav__chevron${isOpen ? ' open' : ''}`} aria-hidden="true">›</span>
                                            </button>

                                            {isOpen && (
                                                <ul className="admin-sidenav__sub-list">
                                                    {group.items.map(({ id, icon, label, badge }) => (
                                                        <li key={id}>
                                                            <button
                                                                type="button"
                                                                className={`admin-sidenav__item admin-sidenav__item--sub${activeTab === id ? ' is-active' : ''}`}
                                                                onClick={() => goToTab(id)}
                                                            >
                                                                <span className="admin-sidenav__icon" aria-hidden="true">{icon}</span>
                                                                <span className="admin-sidenav__label">{label}</span>
                                                                {badge === 'approvals' && pendingApprovals > 0 && (
                                                                    <span className="badge bg-warning text-dark ms-auto" style={{ fontSize: '0.65rem' }}>
                                                                        {pendingApprovals}
                                                                    </span>
                                                                )}
                                                            </button>
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                    );
                                })}
                            </nav>
                        </div>
                    </aside>

                    {/* ── Main Content ────────────────────────────────── */}
                    <main className="col-lg-9 col-md-8 dashboard-main">
                        <ErrorBoundary
                            resetKey={activeTab}
                            fallbackTitle="Operations Panel Unavailable"
                            fallbackMessage="An unexpected error occurred while rendering this operations tab. The navigation drawer and other tabs remain responsive."
                        >
                            {content[activeTab]}
                        </ErrorBoundary>
                    </main>
                </div>
            </div>
        </div>
    );
};

export default AdminDashboard;