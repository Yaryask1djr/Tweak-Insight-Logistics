import Icon from './Icon';

const QueryState = ({
    query,
    loading,
    isEmpty = false,
    empty,
    children,
}) => {
    if (query.isLoading && !query.data) return loading;

    if (query.isError && !query.data) {
        return (
            <div className="dashboard-query-state dashboard-query-state--error" role="alert">
                <Icon name="refresh" size={22} />
                <div>
                    <strong>Could not load this panel</strong>
                    <p>Check your connection and try again.</p>
                    <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => query.refetch()} disabled={query.isFetching}>
                        <Icon name="refresh" size={15} /> {query.isFetching ? 'Retrying...' : 'Retry'}
                    </button>
                </div>
            </div>
        );
    }

    if (isEmpty) return empty;
    return children;
};

export default QueryState;