import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import { showToast } from './Toast';

export default function ReportExportButton({ kind, filters }) {
    const [id, setId] = useState(null);
    const [requesting, setRequesting] = useState(false);
    const status = useQuery({
        queryKey: ['export', id], enabled: Boolean(id),
        queryFn: ({ signal }) => apiGet(`/admin/exports/status?id=${id}`, { signal }),
        refetchInterval: query => ['pending', 'processing'].includes(query.state.data?.data?.status) ? 2000 : false,
        retry: false,
    });
    const state = status.data?.data?.status;
    const pending = requesting || (id && !status.isError && (!state || ['pending', 'processing'].includes(state)));
    const download = async () => {
        try {
            const blob = await apiGet(`/admin/exports/download?id=${id}`, { responseType: 'blob' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a'); link.href = url; link.download = `${kind}-report.csv`; link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch { showToast.error('Export is unavailable or expired. Generate a new report.'); }
    };
    const request = async () => {
        setRequesting(true);
        try {
            const endpoint = kind === 'audit' ? '/admin/audit-log/export' : '/admin/reports/deliveries/export';
            const result = await apiPost(endpoint, filters);
            setId(result.data.export_id);
        } catch { showToast.error('Could not queue the export.'); }
        finally { setRequesting(false); }
    };
    return <div className="d-flex flex-wrap gap-2 align-items-center">
        <button type="button" className="btn btn-sm btn-outline-primary" onClick={request} disabled={Boolean(pending)}>{pending ? 'Preparing report…' : 'Generate CSV'}</button>
        {state === 'ready' && <button type="button" className="btn btn-sm btn-primary" onClick={download}>Download {Number(status.data.data.row_count).toLocaleString()} rows</button>}
        {state === 'ready' && <small className="text-muted">Available for 24 hours</small>}
        {(status.isError || state === 'failed') && <small role="alert" className="text-danger">Export unavailable. Generate a new report.</small>}
    </div>;
}
