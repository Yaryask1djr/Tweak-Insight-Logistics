import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiGet } from '../../api/client';
import Pagination from '../common/Pagination';
import { showToast } from '../common/Toast';
import { TableSkeleton } from '../common/SkeletonLoader';
import QueryState from '../common/QueryState';

const AuditTab = () => {
    const [page, setPage] = useState(1);
    const [filters, setFilters] = useState({
        action: '',
        actor_role: '',
        delivery_id: '',
        from: '',
        to: '',
    });
    const [appliedFilters, setAppliedFilters] = useState(filters);

    const params = new URLSearchParams({
        page,
        limit: 20,
        ...Object.fromEntries(
            Object.entries(appliedFilters).filter(([, value]) => value !== '')
        ),
    });

    const audit = useQuery({
        queryKey: ['admin', 'audit-log', page, appliedFilters],
        queryFn: () => apiGet(`/admin/audit-log?${params}`),
    });

    const exportCsv = async () => {
        try {
            const csvParams = new URLSearchParams({
                format: 'csv',
                ...Object.fromEntries(
                    Object.entries(appliedFilters).filter(([, value]) => value !== '')
                ),
            });
            const result = await apiGet(`/admin/audit-log?${csvParams}`, {
                responseType: 'blob',
            });
            const url = URL.createObjectURL(result);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'operations-audit.csv';
            link.click();
            URL.revokeObjectURL(url);
        } catch {
            showToast.error('Could not export the operations audit log.');
        }
    };

    const entries = audit.data?.data || [];

    if (audit.isError && !audit.data) {
        return <QueryState query={audit} loading={<TableSkeleton rows={6} cols={5} />} />;
    }

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4 className="fw-bold mb-1">Operations Audit</h4>
                    <p className="text-muted mb-0">
                        Immutable record of approvals, document decisions, and assignment changes.
                    </p>
                </div>
                <div className="d-flex gap-2">
                    <button onClick={exportCsv} className="btn btn-sm btn-outline-secondary">
                        Export CSV
                    </button>
                    <button onClick={() => audit.refetch()} className="btn btn-sm btn-outline-primary">
                        Refresh
                    </button>
                </div>
            </div>

            {/* Filter Bar */}
            <div className="row g-2 mb-3">
                <div className="col-md-4">
                    <input
                        value={filters.action}
                        onChange={e => setFilters({ ...filters, action: e.target.value })}
                        placeholder="Search action"
                        className="form-control form-control-sm"
                    />
                </div>
                <div className="col-md-2">
                    <select
                        value={filters.actor_role}
                        onChange={e => setFilters({ ...filters, actor_role: e.target.value })}
                        className="form-select form-select-sm"
                    >
                        <option value="">All roles</option>
                        <option value="admin">Admin</option>
                        <option value="delivery">Driver</option>
                        <option value="client">Client</option>
                        <option value="system">System</option>
                    </select>
                </div>
                <div className="col-md-2">
                    <input
                        value={filters.delivery_id}
                        onChange={e => setFilters({ ...filters, delivery_id: e.target.value })}
                        placeholder="Delivery ID"
                        className="form-control form-control-sm"
                    />
                </div>
                <div className="col-md-2">
                    <input
                        type="date"
                        value={filters.from}
                        onChange={e => setFilters({ ...filters, from: e.target.value })}
                        className="form-control form-control-sm"
                    />
                </div>
                <div className="col-md-2">
                    <button
                        className="btn btn-sm btn-primary w-100"
                        onClick={() => {
                            setPage(1);
                            setAppliedFilters(filters);
                        }}
                    >
                        Apply filters
                    </button>
                </div>
            </div>

            {/* Table or Skeleton */}
            {audit.isLoading ? (
                <TableSkeleton rows={6} cols={5} />
            ) : entries.length === 0 ? (
                <div className="alert alert-light border text-center py-4">
                    <p className="text-muted mb-0">
                        No operational audit records match the selected filters.
                    </p>
                </div>
            ) : (
                <>
                    <div className="table-responsive">
                        <table className="table align-middle">
                            <thead className="table-light">
                                <tr>
                                    <th>Time</th>
                                    <th>Action</th>
                                    <th>Actor</th>
                                    <th>Record</th>
                                    <th>Delivery</th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.map(entry => (
                                    <tr key={entry.id}>
                                        <td>
                                            <small>{new Date(entry.created_at).toLocaleString()}</small>
                                        </td>
                                        <td>
                                            <code>{entry.action}</code>
                                        </td>
                                        <td>{entry.actor_name || entry.actor_role}</td>
                                        <td>
                                            {entry.entity_type} #{entry.entity_id || '—'}
                                        </td>
                                        <td>{entry.delivery_id ? `#${entry.delivery_id}` : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination
                        pagination={audit.data?.pagination || null}
                        onPageChange={setPage}
                    />
                </>
            )}
        </div>
    );
};

export default AuditTab;

