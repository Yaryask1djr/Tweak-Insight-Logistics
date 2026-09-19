import React from 'react';

/**
 * Global & Tab-Level Error Boundary
 *
 * Catches JavaScript errors in child component trees, logs the errors,
 * and renders a resilient fallback UI with a "Retry Component" button.
 * Prevents third-party map APIs (Leaflet) or charts from crashing the
 * navigation drawer, theme toggle, or broader dashboard shell.
 */
class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = {
            hasError: false,
            error: null,
            errorInfo: null,
        };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, errorInfo) {
        this.setState({ errorInfo });
        console.error('ErrorBoundary caught a component error:', error, errorInfo);
        if (typeof this.props.onError === 'function') {
            try {
                this.props.onError(error, errorInfo);
            } catch (err) {
                console.error('Error in ErrorBoundary onError callback:', err);
            }
        }
    }

    componentDidUpdate(prevProps) {
        // Automatically recover when active tab or resetKey changes
        if (this.props.resetKey !== undefined && prevProps.resetKey !== this.props.resetKey && this.state.hasError) {
            this.handleReset();
        }
    }

    handleReset = () => {
        this.setState({
            hasError: false,
            error: null,
            errorInfo: null,
        });
        if (typeof this.props.onReset === 'function') {
            try {
                this.props.onReset();
            } catch (err) {
                console.error('Error in ErrorBoundary onReset callback:', err);
            }
        }
    };

    render() {
        if (this.state.hasError) {
            if (typeof this.props.fallback === 'function') {
                return this.props.fallback({
                    error: this.state.error,
                    reset: this.handleReset,
                });
            }

            const title = this.props.fallbackTitle || 'Component Unavailable';
            const message = this.props.fallbackMessage || 
                'An unexpected error occurred while rendering this component. The rest of your dashboard remains active and secure.';

            return (
                <div className="card border-0 shadow-sm custom-card my-3 p-4 border-start border-danger border-4" role="alert" aria-live="assertive">
                    <div className="d-flex align-items-start">
                        <div className="bg-danger-subtle text-danger p-3 rounded-circle me-3 flex-shrink-0 d-flex align-items-center justify-content-center" style={{ width: '48px', height: '48px', fontSize: '20px' }}>
                            ⚠️
                        </div>
                        <div className="flex-grow-1">
                            <h5 className="fw-bold text-danger mb-1">{title}</h5>
                            <p className="text-muted mb-3 small">{message}</p>

                            {this.state.error && (
                                <details className="mb-3">
                                    <summary className="text-muted small cursor-pointer" style={{ cursor: 'pointer' }}>
                                        Technical Details
                                    </summary>
                                    <pre className="mt-2 p-2 bg-light rounded text-danger small text-wrap font-monospace" style={{ fontSize: '0.8rem', maxHeight: '160px', overflowY: 'auto' }}>
                                        {this.state.error.toString()}
                                        {this.state.errorInfo?.componentStack}
                                    </pre>
                                </details>
                            )}

                            <div className="d-flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    className="btn btn-danger btn-sm px-3 fw-semibold d-inline-flex align-items-center"
                                    onClick={this.handleReset}
                                >
                                    <span className="me-1" aria-hidden="true">↻</span> Retry Component
                                </button>
                                {this.props.secondaryAction && (
                                    <button
                                        type="button"
                                        className="btn btn-outline-secondary btn-sm px-3"
                                        onClick={this.props.secondaryAction.onClick}
                                    >
                                        {this.props.secondaryAction.label}
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}

export default ErrorBoundary;
