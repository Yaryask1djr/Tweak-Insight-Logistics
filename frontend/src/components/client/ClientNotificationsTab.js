import React, { useState, useEffect } from 'react';
import { apiClient } from '../../api/client';
import { showToast } from '../common/Toast';
import Icon from '../common/Icon';

const ClientNotificationsTab = ({ onTrackDelivery, onRefreshCount }) => {
    const [notifications, setNotifications] = useState([]);
    const [loading, setLoading] = useState(false);
    const [filter, setFilter] = useState('all'); // 'all' | 'unread'

    const fetchNotifications = async () => {
        setLoading(true);
        try {
            const res = await apiClient.get('/notifications?limit=50');
            setNotifications(res.data?.data || []);
        } catch (err) {
            console.warn('[ClientNotificationsTab] Fetch failed:', err?.message || err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchNotifications();
    }, []);

    const handleMarkAllRead = async () => {
        try {
            await apiClient.post('/notifications/read-all', {});
            setNotifications(prev => prev.map(n => ({ ...n, delivery_status: 'read' })));
            onRefreshCount?.();
            showToast.success('All notifications marked as read.');
        } catch (err) {
            showToast.error(err.response?.data?.message || 'Could not mark notifications as read.');
        }
    };

    const handleItemClick = async (notif) => {
        if (notif.delivery_status !== 'read') {
            try {
                await apiClient.post('/notifications/read', { notification_id: notif.id });
                setNotifications(prev =>
                    prev.map(n => (n.id === notif.id ? { ...n, delivery_status: 'read' } : n))
                );
                onRefreshCount?.();
            } catch {
                // Non-blocking
            }
        }

        if (notif.delivery_id && onTrackDelivery) {
            onTrackDelivery({
                id: notif.delivery_id,
                tracking_number: notif.payload?.reference || notif.payload?.tracking_number
            });
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
            case 'delivered':
            case 'client.delivered':
                return '✅';
            case 'delay':
            case 'client.delay':
                return '⏱️';
            case 'kyc_verified':
            case 'kyc_rejected':
                return '🪪';
            default:
                return '🔔';
        }
    };

    const filtered = notifications.filter(n => {
        if (filter === 'unread') return n.delivery_status !== 'read';
        return true;
    });

    const unreadCount = notifications.filter(n => n.delivery_status !== 'read').length;

    return (
        <div className="client-notifications-tab">
            {/* Top Bar */}
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div>
                    <h4 className="fw-bold mb-1 text-dark">Notifications & Alerts</h4>
                    <p className="text-muted small mb-0">
                        Stay informed on booking status, driver assignment, dispatch transit, and deliveries
                    </p>
                </div>
                <div className="d-flex align-items-center gap-2">
                    <button
                        className="btn btn-sm btn-outline-secondary"
                        onClick={fetchNotifications}
                        title="Refresh notifications"
                    >
                        <Icon name="refresh" size={14} /> Refresh
                    </button>
                    {unreadCount > 0 && (
                        <button
                            className="btn btn-sm btn-primary fw-semibold"
                            onClick={handleMarkAllRead}
                        >
                            ✓ Mark All as Read ({unreadCount})
                        </button>
                    )}
                </div>
            </div>

            {/* Filter Tabs */}
            <div className="card border-0 shadow-sm custom-card p-4">
                <div className="d-flex gap-2 mb-4 border-bottom pb-3">
                    <button
                        className={`btn btn-sm ${filter === 'all' ? 'btn-dark fw-bold' : 'btn-outline-secondary'}`}
                        onClick={() => setFilter('all')}
                    >
                        All ({notifications.length})
                    </button>
                    <button
                        className={`btn btn-sm ${filter === 'unread' ? 'btn-dark fw-bold' : 'btn-outline-secondary'}`}
                        onClick={() => setFilter('unread')}
                    >
                        Unread {unreadCount > 0 && <span className="badge bg-danger ms-1">{unreadCount}</span>}
                    </button>
                </div>

                {loading ? (
                    <div className="text-center py-5 text-muted">
                        <div className="spinner-border spinner-border-sm text-primary me-2" role="status" />
                        Loading alerts...
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="text-center py-5 bg-light rounded border">
                        <div className="fs-1 mb-2">🔔</div>
                        <h6 className="fw-bold">No notifications to display</h6>
                        <p className="text-muted small mb-0">
                            {filter === 'unread' ? 'All notifications have been read.' : 'You have no alerts at this time.'}
                        </p>
                    </div>
                ) : (
                    <div className="d-flex flex-column gap-3">
                        {filtered.map(item => {
                            const isUnread = item.delivery_status !== 'read';
                            const payload = item.payload || {};
                            return (
                                <div
                                    key={item.id}
                                    className={`p-3 rounded border transition-all ${isUnread ? 'bg-light border-primary-subtle shadow-sm' : 'bg-white'}`}
                                    style={{
                                        borderLeftWidth: isUnread ? '4px' : '1px',
                                        borderLeftColor: isUnread ? '#c9182b' : undefined,
                                        cursor: item.delivery_id ? 'pointer' : 'default'
                                    }}
                                    onClick={() => handleItemClick(item)}
                                >
                                    <div className="d-flex justify-content-between align-items-start gap-3">
                                        <div className="d-flex align-items-start gap-3">
                                            <span className="fs-3" aria-hidden="true">
                                                {getIconForType(item.type)}
                                            </span>
                                            <div>
                                                <div className="d-flex align-items-center gap-2 mb-1">
                                                    <h6 className="fw-bold mb-0 text-dark" style={{ fontSize: '0.95rem' }}>
                                                        {item.title || 'Logistics Update'}
                                                    </h6>
                                                    {isUnread && (
                                                        <span className="badge bg-danger" style={{ fontSize: '0.65rem' }}>
                                                            NEW
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-muted small mb-2" style={{ lineHeight: '1.4' }}>
                                                    {item.message}
                                                </p>
                                                {payload.reference && (
                                                    <span className="badge bg-light text-muted border font-monospace me-2" style={{ fontSize: '0.75rem' }}>
                                                        Ref: {payload.reference}
                                                    </span>
                                                )}
                                                <small className="text-muted" style={{ fontSize: '0.75rem' }}>
                                                    {item.created_at ? new Date(item.created_at).toLocaleString() : 'Just now'}
                                                </small>
                                            </div>
                                        </div>

                                        {item.delivery_id && (
                                            <button
                                                className="btn btn-sm btn-outline-primary fw-semibold px-3 text-nowrap"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    handleItemClick(item);
                                                }}
                                            >
                                                Track →
                                            </button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
};

export default ClientNotificationsTab;
