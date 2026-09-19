import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from './AuthContext';
import ThemeToggle from './common/ThemeToggle';
import KycStatusBanner from './common/KycStatusBanner';
import VerifiedBadge from './common/VerifiedBadge';
import { apiGet } from '../api/client';
import { queryClient } from '../api/queryClient';
import DriverOverviewTab from './driver/DriverOverviewTab';
import PartnerOperationsTab from './driver/PartnerOperationsTab';
import AvailableJobsTab from './driver/AvailableJobsTab';
import ActiveDeliveriesTab from './driver/ActiveDeliveriesTab';
import CompletedDeliveriesTab from './driver/CompletedDeliveriesTab';
import EarningsTab from './driver/EarningsTab';
import DriverNotificationsTab from './driver/DriverNotificationsTab';
import DriverPerformanceTab from './driver/DriverPerformanceTab';
import ErrorBoundary from './common/ErrorBoundary';
import Icon from './common/Icon';

const navGroups = [
    {
        key: 'overview-section',
        label: null, // Pinned at top
        items: [
            { id: 'overview', icon: '🏠', label: 'Overview' }
        ]
    },
    {
        key: 'operations',
        label: '🚚 OPERATIONS',
        items: [
            { id: 'available',   icon: '📋', label: 'Available Jobs' },
            { id: 'active',      icon: '🚚', label: 'Active Assignment' },
            { id: 'completed',   icon: '✅', label: 'Completed Orders' },
            { id: 'performance', icon: '📊', label: 'Performance' },
        ]
    },
    {
        key: 'finance',
        label: '💰 FINANCE',
        items: [
            { id: 'earnings', icon: '💰', label: 'Earnings & Payouts' }
        ]
    },
    {
        key: 'account',
        label: '👤 ACCOUNT',
        items: [
            { id: 'notifications',   icon: '🔔', label: 'Notifications' },
            { id: 'profile-vehicle', icon: '🛵', label: 'Profile & Vehicle' },
            { id: 'kyc-documents',   icon: '🪪', label: 'KYC & Documents' },
            { id: 'settings',        icon: '⚙️', label: 'Settings' }
        ]
    }
];

const mobileTabs = [
    ['overview',        '🏠', 'Overview'],
    ['available',       '📋', 'Available'],
    ['active',          '🚚', 'Active'],
    ['earnings',        '💰', 'Earnings'],
    ['profile-vehicle', '👤', 'Account'],
];

const DeliveryPersonDashboard = () => {
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const [activeTab, setActiveTab] = useState('overview');
    const [highContrast, setHighContrast] = useState(() => {
        return localStorage.getItem('til-driver-high-contrast') === 'true';
    });

    useEffect(() => {
        localStorage.setItem('til-driver-high-contrast', String(highContrast));
    }, [highContrast]);

    const toggleHighContrast = () => {
        setHighContrast(prev => !prev);
    };

    // Driver Operations Profile & KYC
    const driverProfile = useQuery({
        queryKey: ['driver', 'operations-profile'],
        queryFn: () => apiGet('/delivery-person/operations-profile'),
        select: response => response.data?.profile,
        staleTime: 30000,
    });

    // Badge Counters: Active and Available
    const activeQuery = useQuery({
        queryKey: ['driver', 'active-assignments'],
        queryFn: () => apiGet('/delivery-person/my-assignments?status=all').then(res => res.data || []),
        select: items => items.filter(item => item.status !== 'delivered' && item.status !== 'cancelled'),
        staleTime: 15000,
    });

    const availableQuery = useQuery({
        queryKey: ['driver', 'available-jobs'],
        queryFn: () => apiGet('/delivery-person/available-deliveries').then(res => res.data || []),
        staleTime: 15000,
    });

    const notifQuery = useQuery({
        queryKey: ['driver', 'notifications-badge'],
        queryFn: () => apiGet('/notifications?limit=30').then(res => res.data || res),
        staleTime: 20000,
    });

    useEffect(() => {
        if (!user || user.role !== 'delivery') {
            navigate('/login');
        }
    }, [navigate, user]);

    const isKycVerified = driverProfile.data?.kyc_status === 'verified';
    const rawAvailability = driverProfile.data?.availability_status;
    const driverStatus = (rawAvailability === 'online' ? 'available' : rawAvailability) || 'offline';
    const activeCount = activeQuery.data?.length || 0;
    const availableCount = availableQuery.data?.length || 0;
    const notificationsList = Array.isArray(notifQuery.data)
        ? notifQuery.data
        : (notifQuery.data?.notifications || []);
    const unreadNotifCount = notificationsList.filter(n => !n.is_read).length;

    const handleLogout = () => {
        queryClient.clear();
        logout();
        navigate('/');
    };

    const openTab = id => {
        if (['available', 'active'].includes(id) && !isKycVerified) {
            setActiveTab('kyc-documents');
            return;
        }
        setActiveTab(id);
    };

    const content = {
        'overview':        <DriverOverviewTab onNavigate={openTab} />,
        'available':       <AvailableJobsTab />,
        'active':          <ActiveDeliveriesTab />,
        'completed':       <CompletedDeliveriesTab />,
        'performance':     <DriverPerformanceTab />,
        'earnings':        <EarningsTab />,
        'notifications':   <DriverNotificationsTab />,
        'partner-profile': <PartnerOperationsTab initialSection="profile-vehicle" />,
        'profile-vehicle': <PartnerOperationsTab initialSection="profile-vehicle" />,
        'kyc-documents':   <PartnerOperationsTab initialSection="kyc-documents" />,
        'settings':        <PartnerOperationsTab initialSection="settings" />,
    };

    return (
        <div
            className={`min-vh-100 bg-light d-flex flex-column dashboard-shell driver-dashboard ${
                highContrast ? 'driver-high-contrast' : ''
            }`}
            data-driver-contrast={highContrast ? 'high' : 'normal'}
        >
            {/* ── Top Navbar ──────────────────────────────────────────── */}
            <nav className="navbar navbar-dark px-4 py-3 app-navbar dashboard-header driver-dashboard-header">
                <div className="container-fluid">
                    <span className="navbar-brand fw-bold fs-4 d-flex align-items-center gap-2">
                        🚀 Tweak Insight{' '}
                        <span className="badge badge-role-partner">Delivery Partner</span>
                    </span>

                    <div className="text-white d-flex align-items-center gap-2 gap-sm-3">
                        <span className="fw-semibold dashboard-user-name d-none d-sm-inline">
                            👤 {user?.full_name}
                        </span>

                        {/* Availability Pill Indicator */}
                        <span 
                            className={`badge d-inline-flex align-items-center gap-1 py-1 px-2 text-uppercase ${
                                driverStatus === 'available' 
                                    ? 'bg-success text-white' 
                                    : driverStatus === 'paused'
                                    ? 'bg-warning text-dark'
                                    : 'bg-secondary text-white'
                            }`}
                            style={{ fontSize: '0.75rem', borderRadius: '50px' }}
                            title={`Status: ${driverStatus}`}
                        >
                            <span 
                                className="rounded-circle bg-white d-inline-block" 
                                style={{ width: '6px', height: '6px' }} 
                            />
                            {driverStatus}
                        </span>

                        <button
                            type="button"
                            className={`btn btn-sm ${
                                highContrast
                                    ? 'btn-warning text-dark fw-bold border-2'
                                    : 'btn-outline-light'
                            } d-flex align-items-center gap-1 driver-contrast-btn`}
                            style={{ minHeight: '38px' }}
                            onClick={toggleHighContrast}
                            title={
                                highContrast
                                    ? 'High-Contrast Sunlight Mode is active'
                                    : 'Enable High-Contrast Sunlight Mode for outdoor screen visibility'
                            }
                            aria-label="Toggle High-Contrast Sunlight Mode"
                            aria-pressed={highContrast}
                        >
                            <Icon name={highContrast ? 'sun' : 'contrast'} />
                            <span className="d-none d-md-inline">
                                {highContrast ? 'Sunlight Mode: ON' : 'Sunlight Mode'}
                            </span>
                        </button>

                        <ThemeToggle compact />

                        <button
                            className="btn btn-sm btn-outline-light px-3"
                            onClick={handleLogout}
                        >
                            Logout
                        </button>
                    </div>
                </div>
            </nav>

            {/* ── Main Dashboard Content ──────────────────────────────── */}
            <div className="container-fluid px-4 py-4 flex-grow-1 driver-dashboard-content dashboard-content">
                <div className="row g-4">

                    {/* ── Desktop Grouped Sidebar ──────────────────────────── */}
                    <aside className="col-lg-3 col-md-4 dashboard-sidebar driver-desktop-sidebar">
                        <div className="card border-0 shadow-sm custom-card p-3">
                            {/* Driver Profile Summary */}
                            <div className="text-center py-3 border-bottom mb-3">
                                <div
                                    className="bg-warning text-dark rounded-circle d-inline-flex align-items-center justify-content-center mb-2 fw-bold shadow-sm"
                                    style={{ width: '54px', height: '54px', fontSize: '24px' }}
                                >
                                    {user?.full_name ? user.full_name.charAt(0).toUpperCase() : 'D'}
                                </div>
                                <h6 className="fw-bold mb-0 text-dark">{user?.full_name}</h6>
                                <small className="text-muted d-block">Kano Dispatch Partner</small>

                                <div className="mt-2 d-flex justify-content-center gap-1 align-items-center flex-wrap">
                                    <VerifiedBadge
                                        status={driverProfile.data?.kyc_status}
                                        label="KYC verified"
                                    />
                                </div>
                            </div>

                            {/* Grouped Sidebar Navigation */}
                            <div className="driver-grouped-nav d-flex flex-column gap-3">
                                {navGroups.map(group => (
                                    <div key={group.key} className="driver-nav-group">
                                        {group.label && (
                                            <div 
                                                className="driver-nav-group-title text-uppercase fw-bold text-muted px-2 mb-1" 
                                                style={{ fontSize: '0.72rem', letterSpacing: '0.5px' }}
                                            >
                                                {group.label}
                                            </div>
                                        )}
                                        <div className="list-group">
                                            {group.items.map(item => {
                                                const isActive = activeTab === item.id;
                                                const isGated = ['available', 'active'].includes(item.id) && !isKycVerified;

                                                return (
                                                    <button
                                                        key={item.id}
                                                        className={`list-group-item list-group-item-action d-flex align-items-center justify-content-between py-2 px-3 rounded-2 mb-1 border-0 ${
                                                            isActive ? 'active fw-bold' : 'text-dark'
                                                        }`}
                                                        style={{
                                                            transition: 'all 0.15s ease',
                                                            backgroundColor: isActive ? 'var(--til-red, #c9182b)' : 'transparent',
                                                            color: isActive ? '#ffffff' : 'inherit'
                                                        }}
                                                        onClick={() => openTab(item.id)}
                                                    >
                                                        <div className="d-flex align-items-center gap-2">
                                                            <span aria-hidden="true">{item.icon}</span>
                                                            <span>{item.label}</span>
                                                            {isGated && <span className="small opacity-75">🔒</span>}
                                                        </div>

                                                        {/* Badge Counters */}
                                                        {item.id === 'available' && availableCount > 0 && (
                                                            <span 
                                                                className={`badge rounded-pill ${
                                                                    isActive ? 'bg-white text-dark' : 'bg-primary text-white'
                                                                }`} 
                                                                style={{ fontSize: '0.7rem' }}
                                                            >
                                                                {availableCount}
                                                            </span>
                                                        )}
                                                        {item.id === 'active' && activeCount > 0 && (
                                                            <span 
                                                                className={`badge rounded-pill ${
                                                                    isActive ? 'bg-white text-dark' : 'bg-warning text-dark'
                                                                }`} 
                                                                style={{ fontSize: '0.7rem' }}
                                                            >
                                                                {activeCount}
                                                            </span>
                                                        )}
                                                        {item.id === 'notifications' && unreadNotifCount > 0 && (
                                                            <span 
                                                                className={`badge rounded-pill ${
                                                                    isActive ? 'bg-white text-dark' : 'bg-danger text-white'
                                                                }`} 
                                                                style={{ fontSize: '0.7rem' }}
                                                            >
                                                                {unreadNotifCount}
                                                            </span>
                                                        )}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </aside>

                    {/* ── Main Content Area ───────────────────────────────── */}
                    <main className="col-lg-9 col-md-8 driver-dashboard-main dashboard-main">
                        {/* Only show KYC banner if action is required (suppress green 'Identity verified' banner) */}
                        {driverProfile.data?.kyc_status && driverProfile.data?.kyc_status !== 'verified' && (
                            <KycStatusBanner
                                status={driverProfile.data?.kyc_status}
                                rejectionReason={driverProfile.data?.kyc_rejection_reason}
                                onAction={() => setActiveTab('kyc-documents')}
                            />
                        )}
                        <ErrorBoundary
                            resetKey={activeTab}
                            fallbackTitle="Driver Console Unavailable"
                            fallbackMessage="An unexpected error occurred while loading this tab. Your active job status and profile remain secure."
                        >
                            {content[activeTab] || content['overview']}
                        </ErrorBoundary>
                    </main>
                </div>
            </div>

            {/* ── Mobile Bottom Navigation ────────────────────────────── */}
            <nav className="driver-mobile-nav" aria-label="Driver dashboard navigation">
                {mobileTabs.map(([id, icon, label]) => (
                    <button
                        key={id}
                        type="button"
                        className={activeTab === id ? 'active' : ''}
                        onClick={() => openTab(id)}
                        aria-current={activeTab === id ? 'page' : undefined}
                    >
                        <span aria-hidden="true">{icon}</span>
                        <span>{label}</span>
                    </button>
                ))}
            </nav>
        </div>
    );
};

export default DeliveryPersonDashboard;