import React from 'react';

const Pagination = ({ pagination, onPageChange, onLimitChange }) => {
    if (!pagination || pagination.total_records === 0) {
        return null;
    }

    const {
        current_page = 1,
        per_page = 10,
        total_records = 0,
        total_pages = 1,
        has_next_page = false,
        has_prev_page = false
    } = pagination;

    if (total_pages <= 1 && total_records <= per_page) {
        return (
            <div className="d-flex justify-content-between align-items-center py-2 px-1 text-muted small">
                <span>Showing {total_records} of {total_records} record{total_records !== 1 ? 's' : ''}</span>
            </div>
        );
    }

    const startRecord = Math.min((current_page - 1) * per_page + 1, total_records);
    const endRecord = Math.min(current_page * per_page, total_records);

    // Calculate smart window of page numbers
    const getPageNumbers = () => {
        const pages = [];
        const maxVisible = 5;

        if (total_pages <= maxVisible) {
            for (let i = 1; i <= total_pages; i++) {
                pages.push(i);
            }
        } else {
            let start = Math.max(1, current_page - 2);
            let end = Math.min(total_pages, current_page + 2);

            if (current_page <= 3) {
                start = 1;
                end = maxVisible;
            } else if (current_page >= total_pages - 2) {
                start = total_pages - maxVisible + 1;
                end = total_pages;
            }

            for (let i = start; i <= end; i++) {
                pages.push(i);
            }
        }
        return pages;
    };

    const pages = getPageNumbers();

    return (
        <div className="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 pt-3 mt-3 border-top">
            {/* Info and page size selector */}
            <div className="d-flex align-items-center gap-3 text-muted small">
                <span>
                    Showing <strong>{startRecord}</strong>–<strong>{endRecord}</strong> of <strong>{total_records}</strong> entries
                </span>

                {onLimitChange && (
                    <div className="d-inline-flex align-items-center gap-1">
                        <label className="text-muted small">Per page:</label>
                        <select
                            className="form-select form-select-sm"
                            style={{ width: 'auto', padding: '0.15rem 1.75rem 0.15rem 0.5rem', fontSize: '0.8rem' }}
                            value={per_page}
                            onChange={(e) => onLimitChange(Number(e.target.value))}
                        >
                            <option value={5}>5</option>
                            <option value={10}>10</option>
                            <option value={15}>15</option>
                            <option value={25}>25</option>
                            <option value={50}>50</option>
                        </select>
                    </div>
                )}
            </div>

            {/* Pagination Controls */}
            {total_pages > 1 && (
                <nav aria-label="Page navigation">
                    <ul className="pagination pagination-sm mb-0">
                        {/* Prev button */}
                        <li className={`page-item ${!has_prev_page ? 'disabled' : ''}`}>
                            <button
                                className="page-link"
                                onClick={() => has_prev_page && onPageChange(current_page - 1)}
                                disabled={!has_prev_page}
                                aria-label="Previous"
                            >
                                &laquo; Prev
                            </button>
                        </li>

                        {/* First page if window starts after 1 */}
                        {pages[0] > 1 && (
                            <>
                                <li className="page-item">
                                    <button className="page-link" onClick={() => onPageChange(1)}>1</button>
                                </li>
                                {pages[0] > 2 && <li className="page-item disabled"><span className="page-link">…</span></li>}
                            </>
                        )}

                        {/* Page number buttons */}
                        {pages.map((p) => (
                            <li key={p} className={`page-item ${p === current_page ? 'active' : ''}`}>
                                <button
                                    className="page-link"
                                    onClick={() => onPageChange(p)}
                                    style={p === current_page ? { backgroundColor: 'var(--til-red, #dc2626)', borderColor: 'var(--til-red, #dc2626)' } : {}}
                                >
                                    {p}
                                </button>
                            </li>
                        ))}

                        {/* Last page if window ends before total */}
                        {pages[pages.length - 1] < total_pages && (
                            <>
                                {pages[pages.length - 1] < total_pages - 1 && (
                                    <li className="page-item disabled"><span className="page-link">…</span></li>
                                )}
                                <li className="page-item">
                                    <button className="page-link" onClick={() => onPageChange(total_pages)}>{total_pages}</button>
                                </li>
                            </>
                        )}

                        {/* Next button */}
                        <li className={`page-item ${!has_next_page ? 'disabled' : ''}`}>
                            <button
                                className="page-link"
                                onClick={() => has_next_page && onPageChange(current_page + 1)}
                                disabled={!has_next_page}
                                aria-label="Next"
                            >
                                Next &raquo;
                            </button>
                        </li>
                    </ul>
                </nav>
            )}
        </div>
    );
};

export default Pagination;
