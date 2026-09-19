import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiGet } from '../../api/client';
import Pagination from '../common/Pagination';
import { TableSkeleton } from '../common/SkeletonLoader';
import QueryState from '../common/QueryState';

const CompletedDeliveriesTab = () => {
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(10);

    const deliveries = useQuery({
        queryKey: ['driver', 'completed-deliveries', page, limit],
        queryFn: () =>
            apiGet(`/delivery-person/my-assignments?status=delivered&page=${page}&limit=${limit}`),
    });

    const rows = deliveries.data?.data || [];

    if (deliveries.isError && !deliveries.data) {
        return <QueryState query={deliveries} loading={<TableSkeleton rows={5} cols={6} />} />;
    }

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <h4 className="fw-bold mb-3">Completed Deliveries</h4>

            {deliveries.isLoading ? (
                <TableSkeleton rows={5} cols={6} />
            ) : rows.length === 0 ? (
                <div className="alert alert-light text-center py-4 border">
                    <p className="text-muted mb-0">No completed deliveries yet.</p>
                </div>
            ) : (
                <>
                    <div className="table-responsive">
                        <table className="table table-hover align-middle">
                            <thead className="table-light">
                                <tr>
                                    <th>Order #</th>
                                    <th>Item</th>
                                    <th>Client</th>
                                    <th>Destination</th>
                                    <th>Payout (65%)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map(item => (
                                    <tr key={item.id}>
                                        <td data-label="Order" className="fw-bold">
                                            #{item.id}
                                        </td>
                                        <td data-label="Item">{item.item_description}</td>
                                        <td data-label="Client">{item.client_name}</td>
                                        <td data-label="Destination">
                                            <small>{item.delivery_address}</small>
                                        </td>
                                        <td data-label="Payout" className="fw-bold text-success">
                                            ₦{(item.total_cost * 0.65).toFixed(2)}
                                        </td>
                                        <td data-label="Status">
                                            <span className="badge bg-success">Delivered</span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination
                        pagination={deliveries.data?.pagination || null}
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

export default CompletedDeliveriesTab;

