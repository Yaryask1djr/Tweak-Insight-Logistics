import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiGet } from '../../api/client';
import Pagination from '../common/Pagination';
import { StatCardSkeleton, TableSkeleton } from '../common/SkeletonLoader';
import QueryState from '../common/QueryState';

const EarningsTab = () => {
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(15);

    const summary = useQuery({
        queryKey: ['driver', 'earnings-summary'],
        queryFn: () =>
            apiGet('/delivery-person/earnings-summary').then(response => response.data),
    });

    const earnings = useQuery({
        queryKey: ['driver', 'earnings', page, limit],
        queryFn: () =>
            apiGet(`/delivery-person/my-earnings?page=${page}&limit=${limit}`),
    });

    if (summary.isError && !summary.data) {
        return <QueryState query={summary} loading={<StatCardSkeleton count={4} />} />;
    }

    if (summary.isLoading || !summary.data) {
        return (
            <div className="card border-0 shadow-sm custom-card p-4">
                <h4 className="fw-bold mb-4">Earnings & Commission Summary</h4>
                <StatCardSkeleton count={4} />
                <TableSkeleton rows={4} cols={5} />
            </div>
        );
    }

    const info = summary.data;
    const rows = earnings.data?.data || [];
    if (earnings.isError && !earnings.data) {
        return <QueryState query={earnings} loading={<TableSkeleton rows={5} cols={5} />} />;
    }
    const cards = [
        [
            "Today's Earnings",
            `₦${Number(info.today_earned || 0).toLocaleString()}`,
            'stat-card-gradient-primary',
        ],
        [
            'This Week',
            `₦${Number(info.weekly_earned || 0).toLocaleString()}`,
            'stat-card-gradient-purple',
        ],
        [
            'This Month',
            `₦${Number(info.monthly_earned || 0).toLocaleString()}`,
            'stat-card-gradient-success',
        ],
        [
            'Pending Payouts',
            `₦${info.total_pending.toFixed(2)}`,
            'stat-card-gradient-warning',
        ],
        [
            'Total Paid',
            `₦${info.total_earned.toFixed(2)}`,
            'stat-card-gradient-success',
        ],
        ['Completed Trips', info.completed_trips, 'stat-card-gradient-primary'],
    ];

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <h4 className="fw-bold mb-4">Earnings & Commission Summary</h4>

            <div className="row g-3 mb-4">
                {cards.map(([label, value, style]) => (
                    <div className="col-6 col-md-4" key={label}>
                        <div className={`p-3 rounded text-white ${style}`}>
                            <small className="text-white-50 d-block">{label}</small>
                            <h3 className="fw-bold mb-0">{value}</h3>
                        </div>
                    </div>
                ))}
            </div>

            <h5 className="fw-bold mb-3">Earnings Breakdown</h5>

            {earnings.isLoading ? (
                <TableSkeleton rows={5} cols={5} />
            ) : (
                <>
                    <div className="table-responsive">
                        <table className="table table-hover align-middle">
                            <thead className="table-light">
                                <tr>
                                    <th>Earning ID</th>
                                    <th>Order Item</th>
                                    <th>Amount</th>
                                    <th>Type</th>
                                    <th>Payout Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map(earning => (
                                    <tr key={earning.id}>
                                        <td data-label="Earning">#{earning.id}</td>
                                        <td data-label="Order">{earning.item_description}</td>
                                        <td
                                            data-label="Amount"
                                            className="fw-bold text-success"
                                        >
                                            ₦{parseFloat(earning.amount).toFixed(2)}
                                        </td>
                                        <td data-label="Type">
                                            <span className="badge bg-secondary">
                                                {earning.earning_type}
                                            </span>
                                        </td>
                                        <td data-label="Payout status">
                                            <span
                                                className={`badge ${
                                                    earning.status === 'paid'
                                                        ? 'bg-success'
                                                        : 'bg-warning text-dark'
                                                }`}
                                            >
                                                {earning.status}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination
                        pagination={earnings.data?.pagination || null}
                        onPageChange={setPage}
                        onLimitChange={next => {
                            setLimit(next);
                            setPage(1);
                        }}
                    />
                </>
            )}
        </div>
    );
};

export default EarningsTab;

