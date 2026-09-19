import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost } from "../../api/client";
import { showToast } from "../common/Toast";

const EVENT_CATEGORIES = {
    job: {
        label: "New Jobs",
        icon: "🔔",
        match: type => type && (type.includes("new_delivery") || type.includes("offer")),
        badgeClass: "bg-primary",
    },
    assignment: {
        label: "Assignments",
        icon: "📦",
        match: type => type && (type.includes("assignment") || type.includes("delivery_change")),
        badgeClass: "bg-warning text-dark",
    },
    kyc: {
        label: "KYC & Documents",
        icon: "🪪",
        match: type => type && (type.includes("kyc") || type.includes("document")),
        badgeClass: "bg-info text-dark",
    },
    payout: {
        label: "Payouts",
        icon: "💰",
        match: type => type && (type.includes("payout") || type.includes("earning")),
        badgeClass: "bg-success",
    },
};

const getCategory = eventType => {
    return Object.values(EVENT_CATEGORIES).find(c => c.match(eventType)) || {
        label: "System",
        icon: "🔔",
        badgeClass: "bg-secondary",
    };
};

const formatTime = isoString => {
    if (!isoString) return "";
    const d = new Date(isoString);
    const now = new Date();
    const diffMs = now - d;
    const diffMin = Math.floor(diffMs / 60000);
    const diffHr = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr / 24);
    if (diffMin < 2) return "Just now";
    if (diffMin < 60) return diffMin + " min ago";
    if (diffHr < 24) return diffHr + "h ago";
    if (diffDay === 1) return "Yesterday";
    return d.toLocaleDateString("en-NG", { day: "numeric", month: "short" });
};

const NotificationItem = ({ item, onMarkRead }) => {
    const cat = getCategory(item.event_type);
    return (
        <div
            className={"d-flex gap-3 p-3 border-bottom align-items-start " + (item.is_read ? "bg-white" : "bg-primary-subtle")}
            style={{ cursor: item.is_read ? "default" : "pointer" }}
            onClick={() => !item.is_read && onMarkRead(item.id)}
        >
            <div className={"flex-shrink-0 rounded-circle d-flex align-items-center justify-content-center " + cat.badgeClass}
                style={{ width: 40, height: 40, fontSize: "1.1rem" }}
                aria-hidden="true"
            >
                {cat.icon}
            </div>
            <div className="flex-grow-1 min-w-0">
                <div className="d-flex justify-content-between align-items-start gap-1 mb-1">
                    <span className="fw-semibold small text-dark">{item.title || cat.label}</span>
                    <span className="text-muted small flex-shrink-0">{formatTime(item.created_at)}</span>
                </div>
                {item.message && (
                    <p className="mb-1 small text-muted" style={{ lineHeight: 1.4 }}>{item.message}</p>
                )}
                {!item.is_read && (
                    <span className="badge bg-primary" style={{ fontSize: "0.65rem" }}>New</span>
                )}
            </div>
        </div>
    );
};

const DriverNotificationsTab = () => {
    const qc = useQueryClient();

    const notifQuery = useQuery({
        queryKey: ["driver", "notifications"],
        queryFn: () => apiGet("/notifications?limit=50").then(res => res.data || res),
        staleTime: 20000,
        refetchInterval: 30000,
    });

    const markOne = useMutation({
        mutationFn: id => apiPost("/notifications/read", { notification_id: id }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ["driver", "notifications"] }),
    });

    const markAll = useMutation({
        mutationFn: () => apiPost("/notifications/read-all", {}),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ["driver", "notifications"] });
            showToast.success("All notifications marked as read.");
        },
        onError: () => showToast.error("Failed to mark notifications as read."),
    });

    const notifications = Array.isArray(notifQuery.data) ? notifQuery.data : (notifQuery.data?.notifications || []);
    const unreadCount = notifications.filter(n => !n.is_read).length;

    return (
        <div className="card border-0 shadow-sm custom-card overflow-hidden">
            {/* Header */}
            <div className="d-flex justify-content-between align-items-center p-3 p-md-4 border-bottom bg-white">
                <div>
                    <h4 className="fw-bold mb-0">Notifications</h4>
                    {unreadCount > 0 && (
                        <small className="text-muted">{unreadCount} unread</small>
                    )}
                </div>
                {unreadCount > 0 && (
                    <button
                        className="btn btn-sm btn-outline-secondary"
                        onClick={() => markAll.mutate()}
                        disabled={markAll.isPending}
                    >
                        {markAll.isPending ? "Marking…" : "Mark all read"}
                    </button>
                )}
            </div>

            {/* Category Filter Chips */}
            <div className="d-flex gap-2 flex-wrap p-3 border-bottom bg-light">
                {Object.values(EVENT_CATEGORIES).map(cat => {
                    const count = notifications.filter(n => cat.match(n.event_type)).length;
                    if (count === 0) return null;
                    return (
                        <span key={cat.label} className={"badge rounded-pill " + cat.badgeClass + " px-3 py-2"}>
                            {cat.icon} {cat.label} ({count})
                        </span>
                    );
                })}
            </div>

            {/* Notification List */}
            {notifQuery.isLoading ? (
                <div className="p-4">
                    {[...Array(5)].map((_, i) => (
                        <div key={i} className="d-flex gap-3 mb-3 align-items-start">
                            <div className="placeholder-glow flex-shrink-0">
                                <span className="placeholder rounded-circle d-block" style={{ width: 40, height: 40 }} />
                            </div>
                            <div className="flex-grow-1 placeholder-glow">
                                <span className="placeholder col-8 d-block mb-1" />
                                <span className="placeholder col-12 d-block" style={{ height: 12 }} />
                            </div>
                        </div>
                    ))}
                </div>
            ) : notifQuery.isError ? (
                <div className="alert alert-danger m-3">Failed to load notifications. Please try again.</div>
            ) : notifications.length === 0 ? (
                <div className="text-center py-5 text-muted">
                    <div className="fs-1 mb-2" aria-hidden="true">🔔</div>
                    <p className="mb-0">No notifications yet.</p>
                    <small>You will be notified of new jobs, updates, and payouts here.</small>
                </div>
            ) : (
                <div style={{ maxHeight: "65vh", overflowY: "auto" }}>
                    {notifications.map(n => (
                        <NotificationItem
                            key={n.id}
                            item={n}
                            onMarkRead={id => markOne.mutate(id)}
                        />
                    ))}
                </div>
            )}
        </div>
    );
};

export default DriverNotificationsTab;
