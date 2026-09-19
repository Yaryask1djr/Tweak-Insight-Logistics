import React, { useEffect, useRef } from 'react';

/**
 * CursorPagination — Keyset Cursor Pagination & Infinite Stream Controller
 *
 * Provides a high-performance pagination UI powered by keyset index seeks (WHERE id < :cursor).
 * Eliminates slow offset scans on large delivery histories with an ergonomic "Load More"
 * interface and optional auto-scroll observer.
 */
const CursorPagination = ({
    hasMore,
    isLoadingMore,
    onLoadMore,
    loadedCount = 0,
    totalKnown = null,
    autoScroll = false,
    itemLabel = 'deliveries',
    buttonText = 'Load More Deliveries',
}) => {
    const sentinelRef = useRef(null);

    useEffect(() => {
        if (!autoScroll || !hasMore || isLoadingMore) return;

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting) {
                    onLoadMore();
                }
            },
            { threshold: 0.2, rootMargin: '120px' }
        );

        const currentSentinel = sentinelRef.current;
        if (currentSentinel) {
            observer.observe(currentSentinel);
        }

        return () => {
            if (currentSentinel) {
                observer.unobserve(currentSentinel);
            }
        };
    }, [autoScroll, hasMore, isLoadingMore, onLoadMore]);

    return (
        <div className="cursor-pagination-container my-4 text-center">
            {/* Status & Telemetry Strip */}
            <div className="d-flex flex-wrap justify-content-between align-items-center mb-3 px-2 text-muted small">
                <div className="d-flex align-items-center gap-2">
                    <span className="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                        ⚡ Keyset O(1) Seek
                    </span>
                    <span>
                        Showing <strong>{loadedCount}</strong> {itemLabel}
                        {totalKnown ? ` of ~${totalKnown}` : ''}
                    </span>
                </div>
                {hasMore && (
                    <span className="text-secondary opacity-75">
                        More historical records available
                    </span>
                )}
            </div>

            {/* Load More Button or Infinite Sentinel */}
            {hasMore ? (
                <div className="d-grid gap-2 col-md-6 col-lg-4 mx-auto">
                    <button
                        type="button"
                        className={`btn btn-outline-primary btn-cursor-load-more d-inline-flex align-items-center justify-content-center gap-2 py-2 px-4 shadow-sm ${isLoadingMore ? 'loading' : ''}`}
                        onClick={onLoadMore}
                        disabled={isLoadingMore}
                        aria-label={`Load more ${itemLabel}`}
                    >
                        {isLoadingMore ? (
                            <>
                                <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span>Streaming next batch…</span>
                            </>
                        ) : (
                            <>
                                <span>{buttonText}</span>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </>
                        )}
                    </button>
                    {/* Sentinel for IntersectionObserver */}
                    <div ref={sentinelRef} style={{ height: '1px', width: '100%' }} />
                </div>
            ) : (
                <div className="py-2 text-muted small bg-light rounded-pill border d-inline-block px-4">
                    ✓ All {loadedCount} {itemLabel} loaded (end of historical records)
                </div>
            )}
        </div>
    );
};

export default CursorPagination;
