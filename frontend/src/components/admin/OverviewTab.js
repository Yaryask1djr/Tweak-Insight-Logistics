import { useQuery } from "@tanstack/react-query";
import { apiGet } from "../../api/client";
import { StatCardSkeleton } from "../common/SkeletonLoader";
import Icon from "../common/Icon";
import QueryState from "../common/QueryState";

const OverviewTab = ({ onNavigate }) => {
    const overviewQuery = useQuery({
        queryKey: ["admin", "dashboard-stats"],
        queryFn: () => apiGet("/admin/dashboard-stats").then(result => result.data),
        staleTime: 30000,
    });
    const { data, isLoading, refetch } = overviewQuery;

    if (overviewQuery.isError && !data) {
        return <QueryState query={overviewQuery} loading={<StatCardSkeleton count={5} />} />;
    }

    if (isLoading || !data) return <StatCardSkeleton count={5} />;

    const stats = data;

    const kpiCards = [
        ["Pending review", stats.pending_review, "Requests waiting for operations", "stat-card-gradient-warning", "⌛"],
        ["Active shipments", stats.active_shipments, "Currently in the delivery lifecycle", "stat-card-gradient-info", "↗"],
        ["Delayed shipments", stats.delayed_shipments || 0, "Past their preferred delivery time", "stat-card-gradient-danger", "!"],
        ["Collected revenue", "₦" + Number(stats.collected_revenue || 0).toLocaleString("en-NG", { minimumFractionDigits: 2 }), "Paid delivery revenue", "stat-card-gradient-success", "₦"],
        ["Available drivers", stats.fleet?.available || 0, "Verified partners ready for dispatch", "stat-card-gradient-primary", "●"],
    ];

    const fleetTotal = Number(stats.fleet?.total || 0);
    const fleetAvail = Number(stats.fleet?.available || 0);
    const fleetActive = Number(stats.fleet?.active || 0);
    const fleetPending = Number(stats.fleet?.pending_kyc || 0);

    const totalDeliveries = Number(stats.total_deliveries || 1);
    const deliveredCount = Number(stats.delivered || 0);
    const activeCount = Number(stats.active_shipments || 0);
    const pendingCount = Number(stats.pending_review || 0);
    const delayedCount = Number(stats.delayed_shipments || 0);

    return (
        <div>
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <div>
                    <h4 className="fw-bold mb-0">Platform Overview</h4>
                    <p className="text-muted small mb-0">Real-time metropolitan logistics monitoring for Kano State.</p>
                </div>
                <div className="d-flex gap-2">
                    {onNavigate && (
                        <>
                            <button className="btn btn-sm btn-outline-dark" onClick={() => onNavigate("deliveries")}>
                                📦 Order Monitoring
                            </button>
                            <button className="btn btn-sm btn-outline-danger" onClick={() => onNavigate("exceptions")}>
                                ⚠️ Exceptions ({delayedCount})
                            </button>
                        </>
                    )}
                    <button className="btn btn-sm btn-outline-primary" onClick={() => refetch()}>
                        <Icon name="refresh" size={16} /> Refresh
                    </button>
                </div>
            </div>

            {/* 5 KPI Cards (Retained as specified) */}
            <div className="row g-3 mb-4 admin-kpi-grid">
                {kpiCards.map(([label, value, detail, style, icon]) => (
                    <div className="col-12 col-sm-6 col-xl" key={label}>
                        <div className={"admin-kpi-card " + style}>
                            <div className="admin-kpi-card__top">
                                <span className="admin-kpi-card__icon" aria-hidden="true">{icon}</span>
                                <small>{label}</small>
                            </div>
                            <strong className="admin-kpi-card__value">{value}</strong>
                            <span className="admin-kpi-card__detail">{detail}</span>
                        </div>
                    </div>
                ))}
            </div>

            {/* Concise Operational Summaries Grid */}
            <div className="row g-3 mb-4">
                {/* 1. Order Lifecycle Summary */}
                <div className="col-lg-6">
                    <div className="card border-0 shadow-sm custom-card p-4 h-100">
                        <div className="d-flex justify-content-between align-items-center mb-3">
                            <h5 className="fw-bold mb-0">Delivery Pipeline</h5>
                            <span className="badge bg-light text-dark border">
                                Total: {stats.total_deliveries || 0}
                            </span>
                        </div>

                        <div className="row g-2 text-center mb-3">
                            <div className="col-3">
                                <div className="p-2 bg-light rounded">
                                    <span className="text-muted d-block small" style={{ fontSize: "0.72rem" }}>PENDING</span>
                                    <span className="fs-5 fw-bold text-warning">{pendingCount}</span>
                                </div>
                            </div>
                            <div className="col-3">
                                <div className="p-2 bg-light rounded">
                                    <span className="text-muted d-block small" style={{ fontSize: "0.72rem" }}>ACTIVE</span>
                                    <span className="fs-5 fw-bold text-info">{activeCount}</span>
                                </div>
                            </div>
                            <div className="col-3">
                                <div className="p-2 bg-light rounded">
                                    <span className="text-muted d-block small" style={{ fontSize: "0.72rem" }}>DELIVERED</span>
                                    <span className="fs-5 fw-bold text-success">{deliveredCount}</span>
                                </div>
                            </div>
                            <div className="col-3">
                                <div className="p-2 bg-light rounded">
                                    <span className="text-muted d-block small" style={{ fontSize: "0.72rem" }}>DELAYED</span>
                                    <span className="fs-5 fw-bold text-danger">{delayedCount}</span>
                                </div>
                            </div>
                        </div>

                        {/* Progress Bar of Pipeline */}
                        <div className="progress" style={{ height: "8px" }}>
                            <div className="progress-bar bg-warning" style={{ width: `${(pendingCount / totalDeliveries) * 100}%` }} title="Pending" />
                            <div className="progress-bar bg-info" style={{ width: `${(activeCount / totalDeliveries) * 100}%` }} title="Active" />
                            <div className="progress-bar bg-success" style={{ width: `${(deliveredCount / totalDeliveries) * 100}%` }} title="Delivered" />
                            <div className="progress-bar bg-danger" style={{ width: `${(delayedCount / totalDeliveries) * 100}%` }} title="Delayed" />
                        </div>
                        <div className="d-flex justify-content-between text-muted small mt-2" style={{ fontSize: "0.7rem" }}>
                            <span>Intake: {pendingCount}</span>
                            <span>In-Transit: {activeCount}</span>
                            <span>Fulfilled: {deliveredCount}</span>
                        </div>
                    </div>
                </div>

                {/* 2. Fleet & Driver Readiness Summary */}
                <div className="col-lg-6">
                    <div className="card border-0 shadow-sm custom-card p-4 h-100">
                        <div className="d-flex justify-content-between align-items-center mb-3">
                            <h5 className="fw-bold mb-0">Fleet Readiness</h5>
                            <span className="badge bg-light text-dark border">
                                Drivers: {fleetTotal}
                            </span>
                        </div>

                        <div className="row g-2 text-center mb-3">
                            <div className="col-4">
                                <div className="p-2 bg-light rounded border-start border-success border-3">
                                    <span className="text-muted d-block small" style={{ fontSize: "0.72rem" }}>READY NOW</span>
                                    <span className="fs-5 fw-bold text-success">{fleetAvail}</span>
                                </div>
                            </div>
                            <div className="col-4">
                                <div className="p-2 bg-light rounded border-start border-primary border-3">
                                    <span className="text-muted d-block small" style={{ fontSize: "0.72rem" }}>ON ROADS</span>
                                    <span className="fs-5 fw-bold text-primary">{fleetActive}</span>
                                </div>
                            </div>
                            <div className="col-4">
                                <div className="p-2 bg-light rounded border-start border-warning border-3">
                                    <span className="text-muted d-block small" style={{ fontSize: "0.72rem" }}>PENDING KYC</span>
                                    <span className="fs-5 fw-bold text-warning">{fleetPending}</span>
                                </div>
                            </div>
                        </div>

                        <div className="d-flex justify-content-between align-items-center p-2 bg-light rounded mt-auto">
                            <small className="text-muted">
                                {fleetAvail > 0 ? `🟢 ${fleetAvail} verified Kano dispatch riders available for instant assignment.` : "⚠️ No drivers currently online and available."}
                            </small>
                            {onNavigate && (
                                <button className="btn btn-sm btn-link text-decoration-none py-0" onClick={() => onNavigate("fleet")}>
                                    Manage fleet →
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default OverviewTab;
