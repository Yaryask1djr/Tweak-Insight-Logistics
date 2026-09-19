import { useQuery } from "@tanstack/react-query";
import { apiGet } from "../../api/client";
import { StatCardSkeleton } from "../common/SkeletonLoader";
import Icon from "../common/Icon";

const FinanceOverviewTab = ({ onNavigate }) => {
    const statsQuery = useQuery({
        queryKey: ["admin", "dashboard-stats"],
        queryFn: () => apiGet("/admin/dashboard-stats").then(res => res.data),
        staleTime: 30000,
    });

    const deliveriesQuery = useQuery({
        queryKey: ["admin", "finance-deliveries"],
        queryFn: () => apiGet("/admin/all-deliveries?limit=50").then(res => res.data?.deliveries || res.data?.data || []),
        staleTime: 30000,
    });

    const stats = statsQuery.data || {};
    const deliveries = deliveriesQuery.data || [];

    const totalCollected = Number(stats.collected_revenue || 0);
    const totalVolume = Number(stats.total_volume || 0);
    const pendingReceivables = Math.max(0, totalVolume - totalCollected);

    // Approximate partner disbursements based on 80% payout model
    const partnerDisbursed = totalCollected * 0.8;
    const partnerPending = pendingReceivables * 0.8;

    const cards = [
        {
            label: "Collected Revenue",
            value: "₦" + totalCollected.toLocaleString("en-NG", { minimumFractionDigits: 2 }),
            detail: "Settled delivery revenues",
            style: "stat-card-gradient-success",
            icon: "₦",
        },
        {
            label: "Pending Receivables",
            value: "₦" + pendingReceivables.toLocaleString("en-NG", { minimumFractionDigits: 2 }),
            detail: "Awaiting client settlement",
            style: "stat-card-gradient-warning",
            icon: "⏳",
        },
        {
            label: "Partner Disbursements",
            value: "₦" + partnerDisbursed.toLocaleString("en-NG", { minimumFractionDigits: 2 }),
            detail: "Driver earnings fulfilled",
            style: "stat-card-gradient-primary",
            icon: "🚚",
        },
        {
            label: "Pending Payouts",
            value: "₦" + partnerPending.toLocaleString("en-NG", { minimumFractionDigits: 2 }),
            detail: "Accruing for next payout cycle",
            style: "stat-card-gradient-purple",
            icon: "💳",
        },
    ];

    return (
        <div>
            <div className="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 className="fw-bold mb-1">Financial Overview & Payouts</h4>
                    <p className="text-muted small mb-0">Platform billing, driver settlements, and transaction audits.</p>
                </div>
                <div className="d-flex gap-2">
                    {onNavigate && (
                        <>
                            <button className="btn btn-sm btn-outline-secondary" onClick={() => onNavigate("rates")}>
                                ₦ Rate Cards
                            </button>
                            <button className="btn btn-sm btn-outline-secondary" onClick={() => onNavigate("business")}>
                                🏢 Business Accounts
                            </button>
                        </>
                    )}
                </div>
            </div>

            {/* KPI Cards */}
            {statsQuery.isLoading ? (
                <StatCardSkeleton count={4} />
            ) : (
                <div className="row g-3 mb-4">
                    {cards.map(c => (
                        <div className="col-12 col-sm-6 col-xl-3" key={c.label}>
                            <div className={"admin-kpi-card " + c.style}>
                                <div className="admin-kpi-card__top">
                                    <span className="admin-kpi-card__icon" aria-hidden="true">{c.icon}</span>
                                    <small>{c.label}</small>
                                </div>
                                <strong className="admin-kpi-card__value">{c.value}</strong>
                                <span className="admin-kpi-card__detail">{c.detail}</span>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Recent Transaction Log */}
            <div className="card border-0 shadow-sm custom-card p-4 mb-4">
                <h5 className="fw-bold mb-3">Recent Transactions & Invoices</h5>
                {deliveriesQuery.isLoading ? (
                    <div className="text-muted py-3">Loading transactions…</div>
                ) : deliveries.length === 0 ? (
                    <div className="text-muted py-4 text-center">No transaction records found.</div>
                ) : (
                    <div className="table-responsive">
                        <table className="table table-hover align-middle">
                            <thead className="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Tracking ID</th>
                                    <th>Client</th>
                                    <th>Service</th>
                                    <th>Payment Status</th>
                                    <th>Total Cost</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                {deliveries.slice(0, 15).map(d => (
                                    <tr key={d.id}>
                                        <td className="fw-bold">#{d.id}</td>
                                        <td style={{ fontFamily: "monospace" }}>{d.tracking_number || "—"}</td>
                                        <td>{d.client_name || "Client"}</td>
                                        <td className="text-capitalize">{d.service_type || "Standard"}</td>
                                        <td>
                                            <span className={"badge " + (d.payment_status === "paid" ? "bg-success" : "bg-warning text-dark")}>
                                                {d.payment_status || "pending"}
                                            </span>
                                        </td>
                                        <td className="fw-semibold">
                                            ₦{Number(d.total_cost || 0).toLocaleString("en-NG", { minimumFractionDigits: 2 })}
                                        </td>
                                        <td className="text-muted small">
                                            {d.request_time ? new Date(d.request_time).toLocaleDateString("en-NG", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "—"}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
};

export default FinanceOverviewTab;
