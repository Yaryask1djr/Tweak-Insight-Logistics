import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiPost } from "../../api/client";
import { responseItems } from "../../api/response";
import { showToast } from "../common/Toast";
import SensitiveValue from "../common/SensitiveValue";
import Icon from "../common/Icon";
import Pagination from "../common/Pagination";

const ExceptionsAlertsTab = () => {
    const qc = useQueryClient();
    const [selectedCategory, setSelectedCategory] = useState("all");
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(25);
    const [resolvingId, setResolvingId] = useState(null);
    const [resolveReason, setResolveReason] = useState("");
    const [manualDriver, setManualDriver] = useState({});

    // Fetch deliveries to evaluate operational friction
    const deliveriesQuery = useQuery({
        queryKey: ["admin", "exceptions-deliveries", selectedCategory, page, limit],
        queryFn: ({ signal }) => apiGet(`/admin/exceptions?category=${selectedCategory}&page=${page}&limit=${limit}`, { signal }),
        staleTime: 15000,
        refetchInterval: 30000,
    });

    // Available verified drivers for quick dispatch
    const driversQuery = useQuery({
        queryKey: ["admin", "drivers", "available-fast"],
        queryFn: () => apiGet("/admin/drivers?page=1&limit=100&status=verified&availability=available").then(responseItems),
        staleTime: 20000,
    });

    // Mutations
    const broadcast = useMutation({
        mutationFn: id => apiPost("/admin/broadcast-offer", { delivery_id: id }),
        onSuccess: () => {
            showToast.success("Delivery broadcast to eligible Kano dispatch riders.");
            qc.invalidateQueries({ queryKey: ["admin"] });
        },
        onError: err => showToast.error(err.response?.data?.message || "Failed to broadcast offer."),
    });

    const assign = useMutation({
        mutationFn: ({ id, driverUserId }) => apiPost("/admin/manual-assign", { delivery_id: id, driver_user_id: driverUserId }),
        onSuccess: () => {
            showToast.success("Assigned driver to delivery successfully.");
            qc.invalidateQueries({ queryKey: ["admin"] });
        },
        onError: err => showToast.error(err.response?.data?.message || "Failed to assign driver."),
    });

    const resolve = useMutation({
        mutationFn: ({ id, reason }) => apiPost("/admin/resolve-delivery", { delivery_id: id, status: "cancelled", status_reason: reason }),
        onSuccess: () => {
            showToast.success("Delivery cancelled before pickup.");
            setResolvingId(null);
            setResolveReason("");
            qc.invalidateQueries({ queryKey: ["admin"] });
        },
        onError: err => showToast.error(err.response?.data?.message || "Could not cancel delivery."),
    });

    const result = deliveriesQuery.data?.data || {};
    const displayedItems = result.items || [];
    const counts = result.counts || { all: 0, unassigned: 0, delayed: 0, cancelled: 0 };
    const unassigned = displayedItems.filter(d => ["pending", "under_review", "broadcasted"].includes(d.status) && !d.delivery_person_name);
    const delayed = displayedItems.filter(d => !["delivered", "completed", "cancelled", "rejected", "failed"].includes(d.status) && (
        (d.preferred_delivery_time && new Date(d.preferred_delivery_time) < new Date()) ||
        new Date(d.request_time).getTime() < Date.now() - 150 * 60000));
    const availableDrivers = (driversQuery.data || []).filter(
        d => d.kyc_status === "verified" && d.availability_status === "available"
    );

    if (deliveriesQuery.isError) return <div className="alert alert-danger" role="alert">Exceptions could not be loaded. <button onClick={() => deliveriesQuery.refetch()}>Retry</button></div>;

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
                <div>
                    <h4 className="fw-bold mb-1">Exceptions & Operational Alerts</h4>
                    <p className="text-muted small mb-0">
                        Active bottlenecks requiring operations intervention in Kano.
                    </p>
                </div>
                <button
                    type="button"
                    className="btn btn-sm btn-outline-primary"
                    onClick={() => {
                        deliveriesQuery.refetch();
                        driversQuery.refetch();
                    }}
                >
                    <Icon name="refresh" size={14} /> Refresh Alerts
                </button>
            </div>

            {/* Metric Category Pills */}
            <div className="row g-3 mb-4">
                <div className="col-6 col-md-3">
                    <div
                        className={"card border-0 p-3 h-100 cursor-pointer text-center " + (selectedCategory === "all" ? "bg-dark text-white shadow" : "bg-light text-dark")}
                        style={{ cursor: "pointer", transition: "all 0.15s ease" }}
                        onClick={() => { setPage(1); setSelectedCategory("all"); }}
                    >
                        <span className="small fw-semibold text-uppercase" style={{ fontSize: "0.72rem" }}>All Exceptions</span>
                        <div className="fs-3 fw-bold my-1">{counts.all}</div>
                        <small className={selectedCategory === "all" ? "text-white-50" : "text-muted"}>Total items to review</small>
                    </div>
                </div>
                <div className="col-6 col-md-3">
                    <div
                        className={"card border-0 p-3 h-100 cursor-pointer text-center " + (selectedCategory === "delayed" ? "bg-danger text-white shadow" : "bg-light text-danger")}
                        style={{ cursor: "pointer", transition: "all 0.15s ease" }}
                        onClick={() => { setPage(1); setSelectedCategory("delayed"); }}
                    >
                        <span className="small fw-semibold text-uppercase" style={{ fontSize: "0.72rem" }}>🚨 Delayed</span>
                        <div className="fs-3 fw-bold my-1">{counts.delayed}</div>
                        <small className={selectedCategory === "delayed" ? "text-white-50" : "text-muted"}>Overdue transit window</small>
                    </div>
                </div>
                <div className="col-6 col-md-3">
                    <div
                        className={"card border-0 p-3 h-100 cursor-pointer text-center " + (selectedCategory === "unassigned" ? "bg-warning text-dark shadow" : "bg-light text-warning-emphasis")}
                        style={{ cursor: "pointer", transition: "all 0.15s ease" }}
                        onClick={() => { setPage(1); setSelectedCategory("unassigned"); }}
                    >
                        <span className="small fw-semibold text-uppercase" style={{ fontSize: "0.72rem" }}>⏳ Unassigned</span>
                        <div className="fs-3 fw-bold my-1">{counts.unassigned}</div>
                        <small className="text-muted">Awaiting rider accept</small>
                    </div>
                </div>
                <div className="col-6 col-md-3">
                    <div
                        className={"card border-0 p-3 h-100 cursor-pointer text-center " + (selectedCategory === "cancelled" ? "bg-secondary text-white shadow" : "bg-light text-secondary")}
                        style={{ cursor: "pointer", transition: "all 0.15s ease" }}
                        onClick={() => { setPage(1); setSelectedCategory("cancelled"); }}
                    >
                        <span className="small fw-semibold text-uppercase" style={{ fontSize: "0.72rem" }}>❌ Cancelled / Failed</span>
                        <div className="fs-3 fw-bold my-1">{counts.cancelled}</div>
                        <small className={selectedCategory === "cancelled" ? "text-white-50" : "text-muted"}>Disrupted deliveries</small>
                    </div>
                </div>
            </div>

            {/* List of Exceptions */}
            {deliveriesQuery.isLoading ? (
                <div className="p-4 text-center text-muted">Loading exceptions…</div>
            ) : displayedItems.length === 0 ? (
                <div className="alert alert-success border-0 text-center py-5">
                    <div className="fs-1 mb-2">✅</div>
                    <strong className="d-block fs-5">All Operations Running Smoothly</strong>
                    <p className="text-muted mb-0 small mt-1">
                        No delayed, unassigned, or failed deliveries currently require supervisor intervention.
                    </p>
                </div>
            ) : (
                <div className="d-flex flex-column gap-3">
                    {displayedItems.map(item => {
                        const isDelayed = delayed.some(d => d.id === item.id);
                        const isUnassigned = unassigned.some(u => u.id === item.id);

                        return (
                            <div key={item.id} className="card border shadow-sm p-3">
                                <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                    <div className="d-flex align-items-center gap-2">
                                        <span className="fw-bold">Order #{item.id}</span>
                                        {item.tracking_number && (
                                            <span className="badge bg-light text-dark border" style={{ fontFamily: "monospace" }}>
                                                {item.tracking_number}
                                            </span>
                                        )}
                                        <span className={"badge " + (isDelayed ? "bg-danger" : isUnassigned ? "bg-warning text-dark" : "bg-secondary")}>
                                            {isDelayed ? "Delayed" : isUnassigned ? "Unassigned" : item.status.replaceAll("_", " ")}
                                        </span>
                                    </div>
                                    <div className="text-muted small">
                                        Requested: {item.request_time ? new Date(item.request_time).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : "—"}
                                    </div>
                                </div>

                                <div className="row g-2 mb-3 small">
                                    <div className="col-md-4">
                                        <span className="text-muted d-block">Client:</span>
                                        <strong>{item.client_name}</strong>
                                        <div className="text-muted">
                                            <SensitiveValue value={item.client_phone} kind="phone" label="client phone" />
                                        </div>
                                    </div>
                                    <div className="col-md-5">
                                        <span className="text-muted d-block">Route:</span>
                                        <div>📍 {item.pickup_address}</div>
                                        <div>🏁 {item.delivery_address}</div>
                                    </div>
                                    <div className="col-md-3">
                                        <span className="text-muted d-block">Assigned Partner:</span>
                                        {item.delivery_person_name ? (
                                            <div>
                                                <strong>{item.delivery_person_name}</strong>
                                                <div className="text-muted">
                                                    <SensitiveValue value={item.delivery_person_phone} kind="phone" label="partner phone" />
                                                </div>
                                            </div>
                                        ) : (
                                            <span className="text-danger fw-semibold">None assigned</span>
                                        )}
                                    </div>
                                </div>

                                {/* Action Toolbar */}
                                <div className="d-flex flex-wrap align-items-center gap-2 pt-2 border-top">
                                    {isUnassigned && (
                                        <>
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-primary"
                                                disabled={broadcast.isPending}
                                                onClick={() => broadcast.mutate(item.id)}
                                            >
                                                📢 Re-Broadcast Offer
                                            </button>

                                            <div className="d-flex align-items-center gap-1">
                                                <select
                                                    className="form-select form-select-sm"
                                                    style={{ width: "auto", minWidth: 160 }}
                                                    value={manualDriver[item.id] || ""}
                                                    onChange={e => setManualDriver({ ...manualDriver, [item.id]: e.target.value })}
                                                >
                                                    <option value="">Select available driver…</option>
                                                    {availableDrivers.map(d => (
                                                        <option key={d.user_id} value={d.user_id}>
                                                            {d.full_name} ({d.vehicle_type || "Rider"})
                                                        </option>
                                                    ))}
                                                </select>
                                                <button
                                                    type="button"
                                                    className="btn btn-sm btn-primary"
                                                    disabled={!manualDriver[item.id] || assign.isPending}
                                                    onClick={() => assign.mutate({ id: item.id, driverUserId: manualDriver[item.id] })}
                                                >
                                                    Assign
                                                </button>
                                            </div>
                                        </>
                                    )}

                                    {["pending", "under_review", "broadcasted", "assigned", "driver_en_route"].includes(item.status) && !item.pickup_time && (resolvingId === item.id ? (
                                        <div className="d-flex gap-1 flex-grow-1 align-items-center">
                                            <input
                                                type="text"
                                                className="form-control form-control-sm"
                                                placeholder="Reason for cancelling before pickup…"
                                                aria-label="Cancellation reason"
                                                maxLength={500}
                                                value={resolveReason}
                                                onChange={e => setResolveReason(e.target.value)}
                                            />
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-success text-nowrap"
                                                disabled={!resolveReason.trim() || resolve.isPending}
                                                onClick={() => resolve.mutate({ id: item.id, reason: resolveReason })}
                                            >
                                                Confirm cancellation
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-secondary"
                                                onClick={() => setResolvingId(null)}
                                            >
                                                Keep delivery
                                            </button>
                                        </div>
                                    ) : (
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-outline-secondary ms-auto"
                                            onClick={() => setResolvingId(item.id)}
                                        >
                                            Cancel before pickup
                                        </button>
                                    ))}
                                    {(["picked_up", "in_transit", "arrived"].includes(item.status) || (item.pickup_time && !["delivered", "completed"].includes(item.status))) && (
                                        <small className="text-warning">Parcel custody needs operations review. Cancellation is unavailable after pickup.</small>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
            <Pagination pagination={result.pagination || null} onPageChange={setPage} onLimitChange={value => { setLimit(value); setPage(1); }} />
        </div>
    );
};

export default ExceptionsAlertsTab;
