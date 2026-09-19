import { useState } from 'react';
import { useInfiniteQuery, useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import { showToast } from '../common/Toast';
import { TableSkeleton } from '../common/SkeletonLoader';
import QueryState from '../common/QueryState';
import Pagination from '../common/Pagination';

export const UsersTab = () => {
    const [limit, setLimit] = useState(15);
    const [role, setRole] = useState('all');

    const users = useInfiniteQuery({
        queryKey: ['admin', 'users', limit, role],
        initialPageParam: null,
        queryFn: ({ pageParam }) => apiGet(`/admin/all-users?limit=${limit}&role=${role}${pageParam ? `&cursor=${pageParam}` : ''}`),
        getNextPageParam: lastPage => lastPage.data?.next_cursor ?? undefined,
    });

    const rows = users.data?.pages.flatMap(page => page.data?.items || []) || [];

    if (users.isError && !users.data) {
        return <QueryState query={users} loading={<TableSkeleton rows={5} cols={6} />} />;
    }

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <h4 className="fw-bold mb-0">All Registered Users</h4>
                <div className="d-flex align-items-center gap-2">
                    <label className="text-muted small mb-0 fw-semibold">Filter Role:</label>
                    <select
                        className="form-select form-select-sm"
                        style={{ width: 'auto' }}
                        value={role}
                        onChange={e => {
                            setRole(e.target.value);
                        }}
                    >
                        <option value="all">All Roles</option>
                        <option value="client">Clients</option>
                        <option value="delivery">Delivery Partners</option>
                        <option value="admin">Administrators</option>
                    </select>
                </div>
            </div>

            {users.isLoading ? (
                <TableSkeleton rows={5} cols={6} />
            ) : (
                <>
                    <div className="table-responsive">
                        <table className="table table-hover align-middle">
                            <thead className="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map(user => (
                                    <tr key={user.id}>
                                        <td>#{user.id}</td>
                                        <td className="fw-bold">{user.full_name}</td>
                                        <td>{user.email}</td>
                                        <td>
                                            <span
                                                className={`badge ${
                                                    user.role === 'admin'
                                                        ? 'bg-danger'
                                                        : user.role === 'delivery'
                                                        ? 'bg-warning text-dark'
                                                        : 'bg-primary'
                                                }`}
                                            >
                                                {user.role}
                                            </span>
                                        </td>
                                        <td>{user.phone}</td>
                                        <td>
                                            <span
                                                className={`badge ${
                                                    user.is_approved ? 'bg-success' : 'bg-secondary'
                                                }`}
                                            >
                                                {user.is_approved ? 'Approved' : 'Pending'}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {users.hasNextPage && (
                        <button type="button" className="btn btn-sm btn-outline-primary mt-3" onClick={() => users.fetchNextPage()} disabled={users.isFetchingNextPage}>
                            {users.isFetchingNextPage ? 'Loading more…' : 'Load more users'}
                        </button>
                    )}
                </>
            )}
        </div>
    );
};

export const FleetTab = () => {
    const [page, setPage] = useState(1);
    const [availabilityFilter, setAvailabilityFilter] = useState('all');
    const [searchDriver, setSearchDriver] = useState('');
    const queryClient = useQueryClient();

    const drivers = useQuery({
        queryKey: ['admin', 'drivers', page],
        queryFn: () => apiGet(`/admin/drivers?page=${page}&limit=50`),
    });

    const update = useMutation({
        mutationFn: ({ userId, activeStatus }) =>
            apiPost('/admin/driver-operations', {
                user_id: userId,
                active_status: activeStatus,
            }),
        onSuccess: () => {
            showToast.success('Driver status updated.');
            queryClient.invalidateQueries({ queryKey: ['admin', 'drivers'] });
        },
        onError: () => showToast.error('Could not update driver.'),
    });

    if (drivers.isError && !drivers.data) {
        return <QueryState query={drivers} loading={<TableSkeleton rows={6} cols={7} />} />;
    }

    const rawDrivers = drivers.data?.data || [];
    const filteredDrivers = rawDrivers.filter(d => {
        if (availabilityFilter !== 'all' && (d.availability_status || 'offline') !== availabilityFilter) return false;
        if (searchDriver.trim()) {
            const q = searchDriver.toLowerCase();
            return (
                String(d.full_name || '').toLowerCase().includes(q) ||
                String(d.phone || '').includes(q) ||
                String(d.vehicle_type || '').toLowerCase().includes(q) ||
                String(d.vehicle_registration || '').toLowerCase().includes(q)
            );
        }
        return true;
    });

    const counts = {
        total: rawDrivers.length,
        available: rawDrivers.filter(d => d.availability_status === 'available').length,
        busy: rawDrivers.filter(d => d.availability_status === 'busy').length,
        offline: rawDrivers.filter(d => !d.availability_status || d.availability_status === 'offline').length,
    };

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                <div>
                    <h4 className="fw-bold mb-1">Fleet & Driver Management</h4>
                    <p className="text-muted small mb-0">
                        Verified Kano delivery partner readiness, duty states, and vehicles.
                    </p>
                </div>
                <button
                    onClick={() => drivers.refetch()}
                    className="btn btn-sm btn-outline-primary"
                >
                    Refresh Fleet
                </button>
            </div>

            {/* Availability Summary Cards */}
            <div className="row g-2 mb-3">
                <div className="col-6 col-md-3">
                    <div className="p-2 bg-light rounded text-center border">
                        <small className="text-muted d-block" style={{ fontSize: '0.72rem' }}>TOTAL REGISTERED</small>
                        <strong className="fs-5">{counts.total}</strong>
                    </div>
                </div>
                <div className="col-6 col-md-3">
                    <div className="p-2 bg-success-subtle rounded text-center border border-success-subtle">
                        <small className="text-success d-block fw-semibold" style={{ fontSize: '0.72rem' }}>AVAILABLE / READY</small>
                        <strong className="fs-5 text-success">{counts.available}</strong>
                    </div>
                </div>
                <div className="col-6 col-md-3">
                    <div className="p-2 bg-warning-subtle rounded text-center border border-warning-subtle">
                        <small className="text-warning-emphasis d-block fw-semibold" style={{ fontSize: '0.72rem' }}>ON TRIP / BUSY</small>
                        <strong className="fs-5 text-warning-emphasis">{counts.busy}</strong>
                    </div>
                </div>
                <div className="col-6 col-md-3">
                    <div className="p-2 bg-light rounded text-center border">
                        <small className="text-muted d-block" style={{ fontSize: '0.72rem' }}>OFFLINE / RESTING</small>
                        <strong className="fs-5 text-muted">{counts.offline}</strong>
                    </div>
                </div>
            </div>

            {/* Filter & Search Bar */}
            <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                <div className="input-group input-group-sm" style={{ maxWidth: 240 }}>
                    <span className="input-group-text bg-white">🔍</span>
                    <input
                        type="search"
                        className="form-control"
                        placeholder="Search rider, phone, vehicle…"
                        value={searchDriver}
                        onChange={e => setSearchDriver(e.target.value)}
                    />
                </div>
                <select
                    className="form-select form-select-sm"
                    style={{ width: 'auto' }}
                    value={availabilityFilter}
                    onChange={e => setAvailabilityFilter(e.target.value)}
                >
                    <option value="all">All Duty States</option>
                    <option value="available">🟢 Available only</option>
                    <option value="busy">🟡 On Trip only</option>
                    <option value="offline">⚪ Offline only</option>
                </select>
            </div>

            {drivers.isLoading ? (
                <TableSkeleton rows={6} cols={7} />
            ) : (
                <>
                    <div className="table-responsive">
                        <table className="table align-middle table-hover">
                            <thead className="table-light">
                                <tr>
                                    <th>Partner</th>
                                    <th>KYC Status</th>
                                    <th>Availability</th>
                                    <th>Vehicle & Reg</th>
                                    <th>Payload Limit</th>
                                    <th>Account Status</th>
                                    <th>Operations Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredDrivers.map(driver => (
                                    <tr key={driver.user_id}>
                                        <td>
                                            <strong>{driver.full_name}</strong>
                                            <small className="d-block text-muted">
                                                {driver.phone}
                                            </small>
                                        </td>
                                        <td>
                                            <span className={`badge ${driver.kyc_status === 'verified' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'}`}>
                                                {driver.kyc_status.replaceAll('_', ' ')}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`badge ${
                                                driver.availability_status === 'available' ? 'bg-success' :
                                                driver.availability_status === 'busy' ? 'bg-warning text-dark' : 'bg-secondary'
                                            }`}>
                                                ● {driver.availability_status || 'offline'}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="fw-semibold">{driver.vehicle_type || 'Motorcycle'}</div>
                                            {driver.vehicle_registration && (
                                                <small className="badge bg-light text-dark border font-monospace">
                                                    {driver.vehicle_registration}
                                                </small>
                                            )}
                                        </td>
                                        <td>
                                            {driver.max_payload_kg
                                                ? `${driver.max_payload_kg} kg`
                                                : 'Standard'}
                                        </td>
                                        <td>
                                            <span className={`badge ${driver.active_status === 'active' ? 'bg-success' : 'bg-secondary'}`}>
                                                {driver.active_status}
                                            </span>
                                        </td>
                                        <td>
                                            <select
                                                value={driver.active_status}
                                                disabled={update.isPending}
                                                onChange={e =>
                                                    update.mutate({
                                                        userId: driver.user_id,
                                                        activeStatus: e.target.value,
                                                    })
                                                }
                                                className="form-select form-select-sm"
                                                style={{ width: 'auto' }}
                                            >
                                                <option value="active">Active (Permitted)</option>
                                                <option value="inactive">Inactive</option>
                                                <option value="suspended">Suspend Access</option>
                                            </select>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination
                        pagination={drivers.data?.pagination || null}
                        onPageChange={setPage}
                    />
                </>
            )}
        </div>
    );
};

const emptyRateCard = () => ({
    name: '',
    service_type: 'same_day',
    effective_from: '',
    is_active: true,
    rules: [
        { rule_code: 'base_fare', amount: 0 },
        { rule_code: 'included_distance_km', amount: 0 },
        { rule_code: 'distance_per_km', amount: 0 },
        { rule_code: 'included_weight_kg', amount: 0 },
        { rule_code: 'weight_per_kg', amount: 0 },
    ],
});

export const RateCardsTab = () => {
    const [form, setForm] = useState(emptyRateCard);
    const queryClient = useQueryClient();

    const cards = useQuery({
        queryKey: ['admin', 'rate-cards'],
        queryFn: () => apiGet('/admin/rate-cards'),
    });

    if (cards.isError && !cards.data) {
        return <QueryState query={cards} loading={<TableSkeleton rows={4} cols={4} />} />;
    }

    const save = useMutation({
        mutationFn: payload => apiPost('/admin/rate-cards', payload),
        onSuccess: () => {
            showToast.success('Rate card saved for future quote integration.');
            setForm(emptyRateCard());
            queryClient.invalidateQueries({ queryKey: ['admin', 'rate-cards'] });
        },
        onError: () => showToast.error('Could not save the rate card.'),
    });

    const changeRule = (index, amount) =>
        setForm(current => ({
            ...current,
            rules: current.rules.map((rule, i) =>
                i === index ? { ...rule, amount: Number(amount) } : rule
            ),
        }));

    const submit = event => {
        event.preventDefault();
        save.mutate({
            ...form,
            effective_from:
                form.effective_from ||
                new Date().toISOString().slice(0, 19).replace('T', ' '),
        });
    };

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <h4 className="fw-bold mb-1">Kano Rate Cards</h4>
            <p className="text-muted">
                The latest active card for each service is applied by the backend to new Kano
                quotes and requests. Existing deliveries keep their original price snapshot.
            </p>

            <form onSubmit={submit} className="border rounded p-3 mb-4">
                <div className="row g-3">
                    <div className="col-md-5">
                        <label className="form-label small">Card name</label>
                        <input
                            required
                            className="form-control"
                            value={form.name}
                            onChange={e => setForm({ ...form, name: e.target.value })}
                            placeholder="Kano same-day standard"
                        />
                    </div>
                    <div className="col-md-3">
                        <label className="form-label small">Service</label>
                        <select
                            className="form-select"
                            value={form.service_type}
                            onChange={e => setForm({ ...form, service_type: e.target.value })}
                        >
                            <option value="same_day">Same-day</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="business">Business</option>
                        </select>
                    </div>
                    <div className="col-md-4">
                        <label className="form-label small">Effective from</label>
                        <input
                            type="datetime-local"
                            className="form-control"
                            value={form.effective_from}
                            onChange={e =>
                                setForm({ ...form, effective_from: e.target.value })
                            }
                        />
                    </div>

                    {form.rules.map((rule, index) => (
                        <div className="col-md-4" key={rule.rule_code}>
                            <label className="form-label small text-capitalize">
                                {rule.rule_code.replaceAll('_', ' ')}
                            </label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                className="form-control"
                                value={rule.amount}
                                onChange={e => changeRule(index, e.target.value)}
                            />
                        </div>
                    ))}

                    <div className="col-12">
                        <button disabled={save.isPending} className="btn btn-primary">
                            {save.isPending ? 'Saving…' : 'Save rate card'}
                        </button>
                    </div>
                </div>
            </form>

            {cards.isLoading ? (
                <TableSkeleton rows={4} cols={4} />
            ) : (
                <div className="table-responsive">
                    <table className="table">
                        <thead className="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Service</th>
                                <th>Effective</th>
                                <th>Status</th>
                                <th>Rules</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(cards.data?.data || []).map(card => (
                                <tr key={card.id}>
                                    <td>{card.name}</td>
                                    <td className="text-capitalize">
                                        {card.service_type.replaceAll('_', ' ')}
                                    </td>
                                    <td>{new Date(card.effective_from).toLocaleString()}</td>
                                    <td>{card.is_active ? 'Active' : 'Inactive'}</td>
                                    <td>
                                        {card.rules
                                            .map(rule => `${rule.rule_code}: ₦${rule.amount}`)
                                            .join(' · ')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
};

export const BusinessAccountsTab = () => {
    const [page, setPage] = useState(1);
    const [form, setForm] = useState({
        owner_user_id: '',
        legal_name: '',
        trading_name: '',
        contact_email: '',
        contact_phone: '',
        account_status: 'pending',
    });
    const queryClient = useQueryClient();

    const accounts = useQuery({
        queryKey: ['admin', 'business-accounts', page],
        queryFn: () => apiGet(`/admin/business-accounts?page=${page}&limit=20`),
    });

    if (accounts.isError && !accounts.data) {
        return <QueryState query={accounts} loading={<TableSkeleton rows={6} cols={6} />} />;
    }

    const save = useMutation({
        mutationFn: payload => apiPost('/admin/business-accounts', payload),
        onSuccess: () => {
            showToast.success('Business account updated.');
            queryClient.invalidateQueries({ queryKey: ['admin', 'business-accounts'] });
        },
        onError: () => showToast.error('Could not update business account.'),
    });

    const create = event => {
        event.preventDefault();
        save.mutate(form, {
            onSuccess: () => {
                setForm({
                    owner_user_id: '',
                    legal_name: '',
                    trading_name: '',
                    contact_email: '',
                    contact_phone: '',
                    account_status: 'pending',
                });
                setPage(1);
            },
        });
    };

    const statusChange = (account, status) =>
        save.mutate({
            id: account.id,
            legal_name: account.legal_name,
            trading_name: account.trading_name,
            contact_email: account.contact_email,
            contact_phone: account.contact_phone,
            credit_limit: account.credit_limit,
            payment_terms_days: account.payment_terms_days,
            account_status: status,
        });

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4 className="fw-bold mb-1">Business Accounts</h4>
                    <p className="text-muted mb-0">
                        Client account status, credit policy, and payment terms.
                    </p>
                </div>
                <button
                    className="btn btn-sm btn-outline-primary"
                    onClick={() => accounts.refetch()}
                >
                    Refresh
                </button>
            </div>

            <form onSubmit={create} className="row g-2 border rounded p-3 mb-4">
                <div className="col-md-2">
                    <input
                        required
                        type="number"
                        min="1"
                        className="form-control form-control-sm"
                        placeholder="Client user ID"
                        value={form.owner_user_id}
                        onChange={e => setForm({ ...form, owner_user_id: e.target.value })}
                    />
                </div>
                <div className="col-md-3">
                    <input
                        required
                        className="form-control form-control-sm"
                        placeholder="Legal business name"
                        value={form.legal_name}
                        onChange={e => setForm({ ...form, legal_name: e.target.value })}
                    />
                </div>
                <div className="col-md-2">
                    <input
                        className="form-control form-control-sm"
                        placeholder="Trading name"
                        value={form.trading_name}
                        onChange={e => setForm({ ...form, trading_name: e.target.value })}
                    />
                </div>
                <div className="col-md-3">
                    <input
                        type="email"
                        className="form-control form-control-sm"
                        placeholder="Business email"
                        value={form.contact_email}
                        onChange={e => setForm({ ...form, contact_email: e.target.value })}
                    />
                </div>
                <div className="col-md-2">
                    <button
                        disabled={save.isPending}
                        className="btn btn-sm btn-primary w-100"
                    >
                        {save.isPending ? 'Saving…' : 'Create account'}
                    </button>
                </div>
                <small className="text-muted">
                    Create for an existing client user. Use User Management to identify and verify
                    the client account first.
                </small>
            </form>

            {accounts.isLoading ? (
                <TableSkeleton rows={6} cols={6} />
            ) : (
                <>
                    <div className="table-responsive">
                        <table className="table align-middle">
                            <thead className="table-light">
                                <tr>
                                    <th>Business</th>
                                    <th>Owner</th>
                                    <th>Contact</th>
                                    <th>Credit</th>
                                    <th>Terms</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(accounts.data?.data || []).map(account => (
                                    <tr key={account.id}>
                                        <td>
                                            <strong>{account.legal_name}</strong>
                                            <small className="d-block text-muted">
                                                {account.trading_name || ''}
                                            </small>
                                        </td>
                                        <td>
                                            {account.owner_name}
                                            <small className="d-block text-muted">
                                                {account.owner_email}
                                            </small>
                                        </td>
                                        <td>
                                            {account.contact_phone ||
                                                account.contact_email ||
                                                '—'}
                                        </td>
                                        <td>₦{Number(account.credit_limit).toLocaleString()}</td>
                                        <td>{account.payment_terms_days} days</td>
                                        <td>
                                            <select
                                                value={account.account_status}
                                                disabled={save.isPending}
                                                onChange={e =>
                                                    statusChange(account, e.target.value)
                                                }
                                                className="form-select form-select-sm"
                                            >
                                                <option value="pending">Pending</option>
                                                <option value="active">Active</option>
                                                <option value="suspended">Suspended</option>
                                                <option value="closed">Closed</option>
                                            </select>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <Pagination
                        pagination={accounts.data?.pagination || null}
                        onPageChange={setPage}
                    />
                </>
            )}
        </div>
    );
};

