import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiGet } from "../../api/client";
import { StatCardSkeleton } from "../common/SkeletonLoader";
import Icon from "../common/Icon";
import QueryState from "../common/QueryState";

const OverviewTab = ({ onNavigate }) => {
    // ── SaaS Tasklist State (Must remain at top before any conditional returns) ──
    const [showTasklist,  setShowTasklist]  = useState(true);
    const [dismissedRecs, setDismissedRecs] = useState([]);

    const overviewQuery = useQuery({
        queryKey: ["admin", "dashboard-stats"],
        queryFn: () => apiGet("/admin/dashboard-stats").then(result => result.data),
        staleTime: 30000,
    });
    const { data, isLoading, refetch } = overviewQuery;

    if (overviewQuery.isError && !data) {
        return <QueryState query={overviewQuery} loading={<StatCardSkeleton count={5} />} />;
    }

    if (isLoading || !data) {
        return <StatCardSkeleton count={5} />;
    }

    const stats = data || {};

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

    // ── SaaS Tasklist ────────────────────────────────────────────────────────

    const tasks = [
        {
            id: 'review_pending',
            icon: '⌛',
            title: 'Process Pending Delivery Requests',
            desc: `${pendingCount} order${pendingCount !== 1 ? 's' : ''} awaiting operations review. Approve, assign a rider, or flag exceptions to keep the dispatch pipeline flowing.`,
            badge: pendingCount === 0 ? 'Clear' : `${pendingCount} Pending`,
            badgeClass: pendingCount === 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle',
            isCompleted: pendingCount === 0,
            action: () => onNavigate('approvals'),
            actionLabel: pendingCount === 0 ? 'View Approvals ✓' : `Review ${pendingCount} →`,
        },
        {
            id: 'clear_delayed',
            icon: '⚠️',
            title: 'Resolve Delayed & Exception Shipments',
            desc: `${delayedCount} shipment${delayedCount !== 1 ? 's are' : ' is'} past their expected delivery window. Escalate, reassign, or close these to maintain your SLA metrics.`,
            badge: delayedCount === 0 ? 'No Delays' : `${delayedCount} Delayed`,
            badgeClass: delayedCount === 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            isCompleted: delayedCount === 0,
            action: () => onNavigate('exceptions'),
            actionLabel: delayedCount === 0 ? 'All Clear ✓' : `Resolve ${delayedCount} →`,
        },
        {
            id: 'approve_drivers',
            icon: '🛵',
            title: 'Approve Pending Driver KYC Applications',
            desc: `${fleetPending} driver application${fleetPending !== 1 ? 's' : ''} awaiting document review. Approving expands your Kano delivery fleet capacity.`,
            badge: fleetPending === 0 ? 'No Queue' : `${fleetPending} Waiting`,
            badgeClass: fleetPending === 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-info-subtle text-info border border-info-subtle',
            isCompleted: fleetPending === 0,
            action: () => onNavigate('fleet'),
            actionLabel: fleetPending === 0 ? 'Fleet Clear ✓' : `Review Drivers →`,
        },
        {
            id: 'fleet_ready',
            icon: '✅',
            title: 'Maintain Active Dispatch-Ready Fleet',
            desc: `${fleetAvail} verified driver${fleetAvail !== 1 ? 's are' : ' is'} available for immediate Kano dispatch. Keep fleet availability above 20% of total registered drivers.`,
            badge: fleetAvail > 0 ? `${fleetAvail} Ready` : 'No Fleet',
            badgeClass: fleetAvail > 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle',
            isCompleted: fleetAvail > 0,
            action: () => onNavigate('fleet'),
            actionLabel: 'Manage Fleet →',
        },
        {
            id: 'revenue_tracked',
            icon: '₦',
            title: 'Monitor Collected Revenue & Financials',
            desc: 'Review platform revenue, collected delivery fees, and outstanding settlement analytics. Ensure all completed deliveries have processed payments.',
            badge: Number(stats.collected_revenue || 0) > 0 ? 'Active' : 'Finance',
            badgeClass: Number(stats.collected_revenue || 0) > 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-info-subtle text-info border border-info-subtle',
            isCompleted: Number(stats.collected_revenue || 0) > 0,
            action: () => onNavigate('finance'),
            actionLabel: 'Open Finance Hub →',
        },
    ];

    const completedTasksCount = tasks.filter(t => t.isCompleted).length;
    const taskProgressPercent = Math.round((completedTasksCount / tasks.length) * 100);

    // ── Admin AI Recommendations ─────────────────────────────────────────────
    const recommendations = [
        {
            id: 'fleet_utilisation',
            tag: 'FLEET OPTIMISATION',
            tagBadgeClass: 'bg-primary-subtle text-primary border border-primary-subtle',
            icon: '🛵',
            metric: 'Fleet utilisation rate',
            title: 'Maintain Optimal Kano Fleet Coverage',
            text: 'Fleet utilisation below 60% indicates excess idle capacity; above 90% risks delayed dispatches. Target 65–80% by managing driver KYC approvals and availability incentives during peak windows.',
            cta: 'Manage Fleet →',
            action: () => onNavigate('fleet'),
        },
        {
            id: 'exception_sla',
            tag: 'SLA COMPLIANCE',
            tagBadgeClass: 'bg-danger-subtle text-danger border border-danger-subtle',
            icon: '⏱️',
            metric: '<2% delay target',
            title: 'Resolve Exceptions Before They Age',
            text: 'Delayed shipments older than 4 hours significantly impact your platform SLA score and client retention. Review the Exceptions tab daily and escalate or reassign proactively.',
            cta: 'View Exceptions →',
            action: () => onNavigate('exceptions'),
        },
        {
            id: 'audit_review',
            tag: 'GOVERNANCE',
            tagBadgeClass: 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            icon: '📋',
            metric: 'Compliance hygiene',
            title: 'Review Recent Operations Audit Logs',
            text: 'Audit logs capture every state transition, manual override, and assignment event. Weekly review of the audit trail helps detect anomalies, unauthorised changes, and operational drift.',
            cta: 'Open Audit Log →',
            action: () => onNavigate('audit'),
        },
        {
            id: 'kano_peak',
            tag: 'ROUTE INTELLIGENCE',
            tagBadgeClass: 'bg-success-subtle text-success border border-success-subtle',
            icon: '📍',
            metric: 'Kano market hours',
            title: 'Pre-Position Fleet for Kano Market Windows',
            text: 'Demand from Kantin Kwari, Sabon Gari, and Challawa Industrial peaks between 8–10 AM and 4–6 PM. Ensure maximum driver availability is online at the start of these windows for peak dispatch throughput.',
            cta: 'Check Fleet Status →',
            action: () => onNavigate('fleet'),
        },
    ].filter(r => !dismissedRecs.includes(r.id));

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

            {/* ── SaaS Operations Tasklist ────────────────────────────────── */}
            <div className="card border-0 shadow-sm custom-card mb-4 overflow-hidden">
                <div className="p-4 border-bottom bg-body-tertiary d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div className="d-flex align-items-center gap-2 mb-1">
                            <span className="fs-5">📋</span>
                            <h5 className="fw-bold mb-0 text-dark">Operations Action Items & Platform Checklist</h5>
                            <span className={`badge ${taskProgressPercent === 100 ? 'bg-success' : 'bg-primary'} px-2 py-1`}>
                                {completedTasksCount} of {tasks.length} Completed ({taskProgressPercent}%)
                            </span>
                        </div>
                        <p className="text-muted small mb-0">
                            Live operational tasks powered by real-time Kano logistics data. Resolve these to maintain platform health.
                        </p>
                    </div>
                    <div className="d-flex align-items-center gap-2">
                        <div className="progress flex-grow-1" style={{ width: '130px', height: '8px' }}>
                            <div
                                className={`progress-bar ${taskProgressPercent === 100 ? 'bg-success' : 'bg-primary'} progress-bar-striped progress-bar-animated`}
                                role="progressbar"
                                style={{ width: `${taskProgressPercent}%` }}
                                aria-valuenow={taskProgressPercent}
                                aria-valuemin="0"
                                aria-valuemax="100"
                            />
                        </div>
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary py-1 px-2"
                            onClick={() => setShowTasklist(prev => !prev)}
                            style={{ fontSize: '0.8rem' }}
                        >
                            {showTasklist ? '▲ Hide' : '▼ Show'}
                        </button>
                    </div>
                </div>

                {showTasklist && (
                    <div className="list-group list-group-flush">
                        {tasks.map((task) => (
                            <div
                                key={task.id}
                                className={`list-group-item p-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 ${task.isCompleted ? 'bg-body-secondary-subtle' : ''}`}
                                style={{ transition: 'background-color 0.2s' }}
                            >
                                <div className="d-flex align-items-start gap-3">
                                    <div
                                        className={`rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 mt-1 ${task.isCompleted ? 'bg-success text-white' : 'border border-2 border-secondary-subtle text-muted'}`}
                                        style={{ width: '28px', height: '28px', fontSize: '0.82rem', fontWeight: 'bold' }}
                                    >
                                        {task.isCompleted ? '✓' : ''}
                                    </div>
                                    <div>
                                        <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                                            <span className={`fw-bold text-dark ${task.isCompleted ? 'text-decoration-line-through opacity-75' : ''}`}>
                                                {task.icon} {task.title}
                                            </span>
                                            <span className={`badge ${task.badgeClass}`} style={{ fontSize: '0.72rem' }}>
                                                {task.badge}
                                            </span>
                                        </div>
                                        <p className="text-muted small mb-0" style={{ maxWidth: '580px', lineHeight: '1.45' }}>
                                            {task.desc}
                                        </p>
                                    </div>
                                </div>
                                <div className="d-flex align-items-center gap-2 flex-shrink-0 align-self-sm-center">
                                    {task.action && (
                                        <button
                                            type="button"
                                            className={`btn btn-sm ${task.isCompleted ? 'btn-outline-secondary' : 'btn-primary'} fw-semibold px-3`}
                                            onClick={task.action}
                                            style={{ fontSize: '0.82rem' }}
                                        >
                                            {task.actionLabel}
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* ── AI-style Admin Operational Recommendations ──────────────── */}
            {recommendations.length > 0 && (
                <div className="mb-4">
                    <div className="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                        <div>
                            <div className="d-flex align-items-center gap-2">
                                <span className="fs-5">💡</span>
                                <h5 className="fw-bold mb-0 text-dark">Smart Operations Recommendations</h5>
                                <span className="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style={{ fontSize: '0.72rem' }}>
                                    Ops AI
                                </span>
                            </div>
                            <p className="text-muted small mb-0">
                                Proactive platform health and fleet optimisation insights for Kano metropolitan operations.
                            </p>
                        </div>
                        {dismissedRecs.length > 0 && (
                            <button
                                type="button"
                                className="btn btn-sm btn-link text-decoration-none text-muted p-0"
                                onClick={() => setDismissedRecs([])}
                                style={{ fontSize: '0.8rem' }}
                            >
                                ↺ Reset Dismissed Tips
                            </button>
                        )}
                    </div>
                    <div className="row g-3">
                        {recommendations.map((rec) => (
                            <div key={rec.id} className="col-md-6">
                                <div className="card border-0 shadow-sm custom-card p-3 h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <div className="d-flex justify-content-between align-items-start mb-2">
                                            <span className={`badge ${rec.tagBadgeClass}`} style={{ fontSize: '0.72rem', letterSpacing: '0.3px' }}>
                                                {rec.icon} {rec.tag}
                                            </span>
                                            <div className="d-flex align-items-center gap-2">
                                                <span className="badge rounded-pill bg-light text-dark border px-2 py-1" style={{ fontSize: '0.72rem' }}>
                                                    {rec.metric}
                                                </span>
                                                <button
                                                    type="button"
                                                    className="btn-close btn-close-sm"
                                                    aria-label="Dismiss recommendation"
                                                    title="Dismiss this tip"
                                                    onClick={() => setDismissedRecs(prev => [...prev, rec.id])}
                                                    style={{ fontSize: '0.65rem' }}
                                                />
                                            </div>
                                        </div>
                                        <h6 className="fw-bold text-dark mb-1" style={{ fontSize: '0.98rem' }}>{rec.title}</h6>
                                        <p className="text-muted small mb-3" style={{ lineHeight: '1.45', fontSize: '0.83rem' }}>
                                            {rec.text}
                                        </p>
                                    </div>
                                    <div className="pt-2 border-top d-flex justify-content-between align-items-center">
                                        <button
                                            type="button"
                                            className="btn btn-link text-decoration-none p-0 fw-semibold text-primary"
                                            onClick={rec.action}
                                            style={{ fontSize: '0.83rem' }}
                                        >
                                            {rec.cta}
                                        </button>
                                        <span className="text-muted small" style={{ fontSize: '0.75rem' }}>Kano Metropolis</span>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
};

export default OverviewTab;
