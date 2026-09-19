import React from 'react';

/**
 * Shimmering Table Skeleton Loader
 */
export const TableSkeleton = ({ rows = 5, cols = 5 }) => {
    return (
        <div className="table-responsive">
            <table className="table align-middle">
                <thead className="table-light">
                    <tr>
                        {Array.from({ length: cols }).map((_, c) => (
                            <th key={c}>
                                <div className="skeleton-box" style={{ width: c === 0 ? '60px' : '90px', height: '16px' }}></div>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {Array.from({ length: rows }).map((_, r) => (
                        <tr key={r}>
                            {Array.from({ length: cols }).map((_, c) => (
                                <td key={c}>
                                    <div 
                                        className="skeleton-box" 
                                        style={{ 
                                            width: c === 0 ? '50px' : c === 1 ? '140px' : '90px', 
                                            height: '16px',
                                            opacity: 0.85
                                        }}
                                    ></div>
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
};

/**
 * Shimmering Card Grid Skeleton Loader (for Delivery Jobs / Active Orders)
 */
export const CardGridSkeleton = ({ count = 4 }) => {
    return (
        <div className="row g-3">
            {Array.from({ length: count }).map((_, i) => (
                <div key={i} className="col-md-6">
                    <div className="card h-100 border-0 shadow-sm custom-card p-3">
                        <div className="d-flex justify-content-between align-items-center mb-2">
                            <div className="skeleton-box" style={{ width: '80px', height: '20px' }}></div>
                            <div className="skeleton-box" style={{ width: '100px', height: '20px', borderRadius: '12px' }}></div>
                        </div>
                        <hr className="my-2" />
                        <div className="mb-2">
                            <div className="skeleton-box mb-1" style={{ width: '120px', height: '14px' }}></div>
                            <div className="skeleton-box" style={{ width: '80%', height: '18px' }}></div>
                        </div>
                        <div className="bg-light p-3 rounded mb-3">
                            <div className="skeleton-box mb-2" style={{ width: '90%', height: '14px' }}></div>
                            <div className="skeleton-box mb-2" style={{ width: '85%', height: '14px' }}></div>
                            <div className="skeleton-box" style={{ width: '70%', height: '14px' }}></div>
                        </div>
                        <div className="skeleton-box w-100" style={{ height: '38px', borderRadius: '6px' }}></div>
                    </div>
                </div>
            ))}
        </div>
    );
};

/**
 * Shimmering Stat Card Skeleton Loader (for Admin / Driver Overview stats)
 */
export const StatCardSkeleton = ({ count = 4 }) => {
    return (
        <div className="row g-3 mb-4">
            {Array.from({ length: count }).map((_, i) => (
                <div key={i} className="col-md-3">
                    <div className="p-3 rounded bg-white shadow-sm border">
                        <div className="skeleton-box mb-2" style={{ width: '100px', height: '14px' }}></div>
                        <div className="skeleton-box" style={{ width: '140px', height: '32px' }}></div>
                    </div>
                </div>
            ))}
        </div>
    );
};

export default TableSkeleton;
