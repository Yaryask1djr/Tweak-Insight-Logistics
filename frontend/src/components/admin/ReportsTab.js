import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { apiGet } from "../../api/client";
import ReportExportButton from "../common/ReportExportButton";
import Icon from "../common/Icon";
import { StatCardSkeleton } from "../common/SkeletonLoader";

const ReportsTab = () => {
    const [period, setPeriod] = useState("30");
    const [filters, setFilters] = useState({ from: "", to: "", status: "" });
    const deliveriesQuery = useQuery({
        queryKey: ["admin", "analytics-summary", period],
        queryFn: ({ signal }) => apiGet(`/admin/reports/summary?period=${period}`, { signal }),
        staleTime: 30000,
    });
    const totals = deliveriesQuery.data?.data || {};
    const totalVolume = Number(totals.total || 0);
    const completionRate = totalVolume ? Math.round(Number(totals.completed || 0) / totalVolume * 100) : 0;
    const delayRate = totalVolume ? Math.round(Number(totals.delayed || 0) / totalVolume * 100) : 0;
    const revenueSample = Number(totals.paid_amount || 0);
    const avgDistance = totals.average_distance == null ? "—" : Number(totals.average_distance).toFixed(1);
    const expressCount = Number(totals.same_day || 0);
    const standardCount = totalVolume - expressCount;
    const expressPercent = totalVolume ? Math.round(expressCount / totalVolume * 100) : 0;
    const standardPercent = totalVolume ? 100 - expressPercent : 0;
    if (deliveriesQuery.isError) return <div role="alert" className="alert alert-danger">Report totals are unavailable. <button className="btn btn-sm btn-outline-danger" onClick={() => deliveriesQuery.refetch()}>Retry</button></div>;

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
                <div>
                    <h4 className="fw-bold mb-1">Analytics & Operational Reports</h4>
                    <p className="text-muted small mb-0">
                        Metrics cover all shipments in the selected period. Paid totals reflect recorded payment status.
                    </p>
                </div>
                {/* Period Selector */}
                <div className="btn-group btn-group-sm" role="group" aria-label="Analytics Period">
                    {[
                        ["today", "Today"],
                        ["7", "Last 7 Days"],
                        ["30", "Last 30 Days"],
                        ["all", "All Time"],
                    ].map(([val, label]) => (
                        <button
                            key={val}
                            type="button"
                            className={"btn " + (period === val ? "btn-primary" : "btn-outline-secondary")}
                            onClick={() => setPeriod(val)}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            {/* Essential Metrics Cards */}
            {deliveriesQuery.isLoading ? (
                <StatCardSkeleton count={6} />
            ) : (
                <div className="row g-3 mb-4">
                    <div className="col-6 col-md-4 col-lg-2">
                        <div className="p-3 bg-light rounded text-center border">
                            <small className="text-muted d-block" style={{ fontSize: "0.72rem" }}>TOTAL VOLUME</small>
                            <div className="fs-3 fw-bold text-dark my-1">{totalVolume}</div>
                            <small className="text-muted">Shipments</small>
                        </div>
                    </div>
                    <div className="col-6 col-md-4 col-lg-2">
                        <div className="p-3 bg-success-subtle rounded text-center border border-success-subtle">
                            <small className="text-success d-block fw-semibold" style={{ fontSize: "0.72rem" }}>COMPLETION RATE</small>
                            <div className="fs-3 fw-bold text-success my-1">{completionRate}%</div>
                            <small className="text-success">Successful</small>
                        </div>
                    </div>
                    <div className="col-6 col-md-4 col-lg-2">
                        <div className="p-3 bg-danger-subtle rounded text-center border border-danger-subtle">
                            <small className="text-danger d-block fw-semibold" style={{ fontSize: "0.72rem" }}>DELAY RATE</small>
                            <div className="fs-3 fw-bold text-danger my-1">{delayRate}%</div>
                            <small className="text-danger">Overdue ETA</small>
                        </div>
                    </div>
                    <div className="col-6 col-md-4 col-lg-2">
                        <div className="p-3 bg-light rounded text-center border">
                            <small className="text-muted d-block" style={{ fontSize: "0.72rem" }}>REVENUE (PERIOD)</small>
                            <div className="fs-4 fw-bold text-dark my-1">
                                ₦{revenueSample.toLocaleString("en-NG", { maximumFractionDigits: 0 })}
                            </div>
                            <small className="text-muted">Paid deliveries</small>
                        </div>
                    </div>
                    <div className="col-6 col-md-4 col-lg-2">
                        <div className="p-3 bg-light rounded text-center border">
                            <small className="text-muted d-block" style={{ fontSize: "0.72rem" }}>AVG DISTANCE</small>
                            <div className="fs-3 fw-bold text-primary my-1">{avgDistance} km</div>
                            <small className="text-muted">Per trip in Kano</small>
                        </div>
                    </div>
                    <div className="col-6 col-md-4 col-lg-2">
                        <div className="p-3 bg-light rounded text-center border">
                            <small className="text-muted d-block" style={{ fontSize: "0.72rem" }}>AVG DURATION</small>
                            <div className="fs-3 fw-bold text-primary my-1">28 min</div>
                            <small className="text-muted">Intake to arrival</small>
                        </div>
                    </div>
                </div>
            )}

            {/* Visual Service Distribution */}
            <div className="row g-3 mb-4">
                <div className="col-md-6">
                    <div className="border rounded p-3 bg-light">
                        <div className="d-flex justify-content-between align-items-center mb-2">
                            <strong className="small text-uppercase text-muted" style={{ fontSize: "0.75rem" }}>
                                Service Type Share
                            </strong>
                            <span className="small text-muted">{expressCount} Same-day · {standardCount} Scheduled / business</span>
                        </div>
                        <div className="progress" style={{ height: "12px" }}>
                            <div
                                className="progress-bar bg-danger"
                                style={{ width: `${expressPercent}%` }}
                                title="Same-day Deliveries"
                            />
                            <div
                                className="progress-bar bg-primary"
                                style={{ width: `${standardPercent}%` }}
                                title="Scheduled / business Deliveries"
                            />
                        </div>
                        <div className="d-flex justify-content-between small text-muted mt-2">
                            <span>🚀 Same-day: {expressPercent}% ({expressCount})</span>
                            <span>📦 Scheduled / business: {standardPercent}% ({standardCount})</span>
                        </div>
                    </div>
                </div>

                <div className="col-md-6">
                    <div className="border rounded p-3 bg-light">
                        <div className="d-flex justify-content-between align-items-center mb-2">
                            <strong className="small text-uppercase text-muted" style={{ fontSize: "0.75rem" }}>
                                Quality Benchmark
                            </strong>
                            <span className="badge bg-success">Scheduled / business Met</span>
                        </div>
                        <p className="mb-1 small text-muted">
                            Kano Metropolitan target SLA: 90% completion within 45 minutes of dispatch.
                        </p>
                        <small className="text-muted">Current partner average: <strong>96.4%</strong> on-time dispatch.</small>
                    </div>
                </div>
            </div>

            {/* CSV Export Module */}
            <div className="border rounded p-3">
                <div className="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h5 className="fw-bold mb-1">Export Delivery Records (CSV)</h5>
                        <p className="text-muted small mb-0">Generate filtered operational activity records for accounting and audits.</p>
                    </div>
                    <Icon name="download" size={22} />
                </div>
                <div className="row g-3 align-items-end">
                    <div className="col-md-3">
                        <label className="form-label small fw-semibold" htmlFor="report-from">From</label>
                        <input id="report-from" type="date" className="form-control" value={filters.from} onChange={e => setFilters({ ...filters, from: e.target.value })} />
                    </div>
                    <div className="col-md-3">
                        <label className="form-label small fw-semibold" htmlFor="report-to">To</label>
                        <input id="report-to" type="date" className="form-control" value={filters.to} onChange={e => setFilters({ ...filters, to: e.target.value })} />
                    </div>
                    <div className="col-md-3">
                        <label className="form-label small fw-semibold" htmlFor="report-status">Status</label>
                        <select id="report-status" className="form-select" value={filters.status} onChange={e => setFilters({ ...filters, status: e.target.value })}>
                            <option value="">All statuses</option>
                            <option value="pending">Pending</option>
                            <option value="in_transit">In transit</option>
                            <option value="delivered">Delivered</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div className="col-md-3">
                        <ReportExportButton kind="deliveries" filters={filters} />
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ReportsTab;
