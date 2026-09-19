import React, { useState, useEffect, useRef } from 'react';
import { apiClient } from '../../api/client';
import { showToast } from '../common/Toast';

const NotificationBell = ({ onTrackDelivery }) => {
    const [open, setOpen] = useState(false);
    const [unreadCount, setUnreadCount] = useState(0);
    const [notifications, setNotifications] = useState([]);
    const [loading, setLoading] = useState(false);
    const dropdownRef = useRef(null);

    const fetchUnreadCount = async () => {
        try {
            const res = await apiClient.get('/notifications/unread-count');
            setUnreadCount(res.data?.data?.unread_count || 0);
        } catch {
            // Silently ignore if table not yet migrated or offline
        }
    };

    const fetchNotifications = async () => {
        setLoading(true);
        try {
            const res = await apiClient.get('/notifications?limit=15');
            const items = res.data?.data || [];
            setNotifications(items);
        } catch (err) {
            console.warn('[NotificationBell] Could not load notifications:', err?.message || err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchUnreadCount();
        const timer = setInterval(fetchUnreadCount, 30000); // Polling every 30s
        return () => clearInterval(timer);
    }, []);

    useEffect(() => {
        if (open) {
            fetchNotifications();
        }
    }, [open]);

    // Close on click outside or escape key
    useEffect(() => {
        const handleClickOutside = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
                setOpen(false);
            }
        };
        const handleKeyDown = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };

        if (open) {
            document.addEventListener('mousedown', handleClickOutside);
            document.addEventListener('keydown', handleKeyDown);
        }
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [open]);

    const handleMarkAllRead = async () => {
        try {
            await apiClient.post('/notifications/read-all', {});
            setUnreadCount(0);
            setNotifications(prev => prev.map(n => ({ ...n, delivery_status: 'read' })));
            showToast.success('All notifications marked as read.');
        } catch (err) {
            showToast.error(err.response?.data?.message || 'Could not mark notifications as read.');
        }
    };

    const handleNotificationClick = async (notif) => {
        if (notif.delivery_status !== 'read') {
            try {
                await apiClient.post('/notifications/read', { notification_id: notif.id });
                setUnreadCount(prev => Math.max(0, prev - 1));
                setNotifications(prev =>
                    prev.map(n => (n.id === notif.id ? { ...n, delivery_status: 'read' } : n))
                );
            } catch {
                // Non-blocking
            }
        }

        if (notif.delivery_id && onTrackDelivery) {
            setOpen(false);
            onTrackDelivery({ id: notif.delivery_id, tracking_number: notif.payload?.reference });
        }
    };

    const getIconForType = (type) => {
        switch (type) {
            case 'driver_assigned':
            case 'client.driver_assigned':
                return '🛵';
            case 'picked_up':
            case 'client.picked_up':
                return '📦';
            case 'in_transit':
            case 'client.in_transit':
                return '🚀';
            case 'arrived':
            case 'client.arrived':
                return '📍';
            case 'delivered':
            case 'client.delivery_completed':
                return '✅';
            case 'delayed':
            case 'exception':
            case 'client.order_exception':
                return '⚠️';
            default:
                return '🔔';
        }
    };

    const formatTime = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        const now = new Date();
        const diffSecs = Math.floor((now - d) / 1000);
        if (diffSecs < 60) return 'Just now';
        if (diffSecs < 3600) return `${Math.floor(diffSecs / 60)}m ago`;
        if (diffSecs < 86400) return `${Math.floor(diffSecs / 3600)}h ago`;
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    };

    return (
        <div className="position-relative" ref={dropdownRef}>
            <button
                type="button"
                className="btn btn-sm btn-outline-light position-relative p-2 d-flex align-items-center justify-content-center"
                onClick={() => setOpen(prev => !prev)}
                aria-label="Notifications"
                aria-expanded={open}
                title="Shipment notifications & alerts"
                style={{ width: '38px', height: '38px', borderRadius: '8px' }}
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                {unreadCount > 0 && (
                    <span
                        className="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-light"
                        style={{ fontSize: '0.7rem', padding: '0.25em 0.5em' }}
                    >
                        {unreadCount > 99 ? '99+' : unreadCount}
                    </span>
                )}
            </button>

            {open && (
                <div
                    className="dropdown-menu dropdown-menu-end show p-0 shadow-lg border-0 notif-dropdown"
                    style={{
                        position: 'absolute',
                        right: 0,
                        top: '115%',
                        width: '340px',
                        maxWidth: '92vw',
                        zIndex: 1050,
                        borderRadius: '12px',
                        overflow: 'hidden'
                    }}
                >
                    <div className="d-flex justify-content-between align-items-center px-3 py-2 bg-dark text-white border-bottom">
                        <div className="d-flex align-items-center gap-2">
                            <span className="fw-bold fs-6">Alerts & Updates</span>
                            {unreadCount > 0 && (
                                <span className="badge bg-danger rounded-pill" style={{ fontSize: '0.7rem' }}>
                                    {unreadCount} new
                                </span>
                            )}
                        </div>
                        {unreadCount > 0 && (
                            <button
                                type="button"
                                className="btn btn-link btn-sm text-light text-decoration-none p-0"
                                style={{ fontSize: '0.8rem' }}
                                onClick={handleMarkAllRead}
                            >
                                Mark all read
                            </button>
                        )}
                    </div>

                    <div className="notif-list" style={{ maxHeight: '380px', overflowY: 'auto' }}>
                        {loading ? (
                            <div className="p-4 text-center text-muted small">
                                <div className="spinner-border spinner-border-sm text-danger me-2" role="status" />
                                Checking notifications…
                            </div>
                        ) : notifications.length === 0 ? (
                            <div className="p-4 text-center text-muted small">
                                <div className="fs-3 mb-1">📭</div>
                                <div className="fw-semibold">No recent alerts</div>
                                <p className="mb-0 text-muted" style={{ fontSize: '0.8rem' }}>
                                    Status updates on your deliveries will appear here in real time.
                                </p>
                            </div>
                        ) : (
                            notifications.map(item => {
                                const isUnread = item.delivery_status !== 'read';
                                return (
                                    <div
                                        key={item.id}
                                        className={`p-3 border-bottom d-flex gap-3 align-items-start notif-item ${
                                            isUnread ? 'bg-light fw-medium' : ''
                                        }`}
                                        style={{ cursor: 'pointer', transition: 'background-color 0.15s ease' }}
                                        onClick={() => handleNotificationClick(item)}
                                    >
                                        <span className="fs-5 mt-1" aria-hidden="true">
                                            {getIconForType(item.notification_type)}
                                        </span>
                                        <div className="flex-grow-1" style={{ minWidth: 0 }}>
                                            <div className="d-flex justify-content-between align-items-baseline gap-1">
                                                <span className="text-dark small fw-bold text-truncate">
                                                    {item.title}
                                                </span>
                                                <small className="text-muted" style={{ fontSize: '0.7rem', whiteSpace: 'nowrap' }}>
                                                    {formatTime(item.created_at)}
                                                </small>
                                            </div>
                                            <p className="text-muted small mb-1 text-break" style={{ fontSize: '0.82rem', lineHeight: '1.3' }}>
                                                {item.body}
                                            </p>
                                            {item.delivery_id && (
                                                <span className="badge bg-primary-subtle text-primary border border-primary-subtle" style={{ fontSize: '0.7rem' }}>
                                                    Track Order →
                                                </span>
                                            )}
                                        </div>
                                        {isUnread && (
                                            <span className="rounded-circle bg-danger mt-2" style={{ width: '8px', height: '8px', flexShrink: 0 }} />
                                        )}
                                    </div>
                                );
                            })
                        )}
                    </div>

                    <div className="p-2 bg-light text-center border-top">
                        <small className="text-muted" style={{ fontSize: '0.75rem' }}>
                            Tweak Insight Operational Dispatch Alerts
                        </small>
                    </div>
                </div>
            )}
        </div>
    );
};

export default NotificationBell;
