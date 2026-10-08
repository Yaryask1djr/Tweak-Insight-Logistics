import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import { useAuth } from '../AuthContext';
import Pagination from './Pagination';
import { showToast } from './Toast';

export default function NotificationInbox({ onTrackDelivery, onRefreshCount }) {
    const { user } = useAuth();
    const qc = useQueryClient();
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(20);
    const [unread, setUnread] = useState(false);
    const [sort, setSort] = useState('newest');
    const [draft, setDraft] = useState('');
    const [search, setSearch] = useState('');
    const prefix = ['notifications', user?.id];
    const inbox = useQuery({
        queryKey: [...prefix, { page, limit, unread, sort, search }],
        queryFn: () => apiGet('/notifications?' + new URLSearchParams({ page, limit, unread, sort, search })),
        staleTime: 20000,
        refetchInterval: 30000,
    });
    const count = useQuery({
        queryKey: [...prefix, 'count'],
        queryFn: () => apiGet('/notifications/unread-count'),
        staleTime: 20000,
        refetchInterval: 30000,
    });
    const refresh = () => {
        qc.invalidateQueries({ queryKey: prefix });
        onRefreshCount?.();
    };
    const mark = useMutation({
        mutationFn: id => id == null ? apiPost('/notifications/read-all', {}) : apiPost('/notifications/read', { notification_id: id }),
        onSuccess: () => { if (unread) setPage(1); refresh(); },
        onError: () => showToast.error('Could not mark notifications as read. Please retry.'),
    });
    const items = Array.isArray(inbox.data?.data) ? inbox.data.data : [];
    const unreadCount = count.data?.data?.unread_count;
    return (
        <section className="card border-0 shadow-sm custom-card p-3 p-md-4" aria-label="Notifications">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                <div><h4 className="fw-bold mb-1">Notifications</h4><p className="small text-muted mb-0">{Number.isInteger(unreadCount) ? `${unreadCount} unread across your inbox` : 'Delivery and account updates'}</p></div>
                <div className="d-flex gap-2">
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={refresh} disabled={inbox.isFetching}>Refresh</button>
                    <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => mark.mutate(null)} disabled={mark.isPending || unreadCount === 0}>Mark all read</button>
                </div>
            </div>
            <form className="d-flex flex-wrap align-items-center gap-2 mb-3" onSubmit={event => { event.preventDefault(); setPage(1); setSearch(draft.trim()); }}>
                <input type="search" className="form-control flex-grow-1 w-auto" aria-label="Search notifications" placeholder="Search notifications" maxLength={100} value={draft} onChange={event => setDraft(event.target.value)} />
                <button type="submit" className="btn btn-outline-secondary">Search</button>
                <select aria-label="Notification status" className="form-select w-auto" value={unread ? 'unread' : 'all'} onChange={event => { setPage(1); setUnread(event.target.value === 'unread'); }}>
                    <option value="all">All updates</option><option value="unread">Unread only</option>
                </select>
                <select aria-label="Notification order" className="form-select w-auto" value={sort} onChange={event => { setPage(1); setSort(event.target.value); }}>
                    <option value="newest">Newest first</option><option value="oldest">Oldest first</option>
                </select>
            </form>
            {inbox.isLoading ? <p role="status">Loading notifications…</p> : inbox.isError ? (
                <div role="alert" className="alert alert-danger">Notifications could not be loaded. <button type="button" className="btn btn-sm btn-outline-danger ms-2" onClick={() => inbox.refetch()}>Retry</button></div>
            ) : items.length === 0 ? <p className="text-muted py-4 text-center">No notifications match this view.</p> : (
                <ul className="list-unstyled mb-0">
                    {items.map(item => <li key={item.id} className={`border rounded p-3 mb-2 ${item.delivery_status !== 'read' ? 'border-primary-subtle bg-light' : ''}`}>
                        <div className="d-flex flex-wrap justify-content-between gap-2"><h5 className="fs-6 fw-semibold mb-1">{item.title}</h5><time className="small text-muted">{item.created_at}</time></div>
                        <p className="small mb-2" style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{item.body}</p>
                        <div className="d-flex align-items-center gap-2">
                            {item.delivery_status !== 'read' ? <button type="button" className="btn btn-sm btn-outline-primary" disabled={mark.isPending} onClick={() => mark.mutate(item.id)}>Mark as read</button> : <span className="small text-muted">Read</span>}
                            {item.delivery_id && onTrackDelivery && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => onTrackDelivery({ id: item.delivery_id, tracking_number: item.payload?.reference || item.payload?.tracking_number })}>View delivery</button>}
                        </div>
                    </li>)}
                </ul>
            )}
            {!inbox.isError && <Pagination pagination={inbox.data?.pagination} onPageChange={setPage} onLimitChange={value => { setPage(1); setLimit(value); }} />}
        </section>
    );
}
