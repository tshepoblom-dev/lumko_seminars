// Labels shared by the Admin → Communications pages.

export const sendStatus = {
    sending: { label: 'Sending', variant: 'info' },
    sent: { label: 'Sent', variant: 'success' },
    partially_failed: { label: 'Some failed', variant: 'warning' },
    failed: { label: 'Failed', variant: 'danger' },
};

export const recipientStatus = {
    queued: { label: 'Queued', variant: 'neutral' },
    sent: { label: 'Sent', variant: 'success' },
    failed: { label: 'Failed', variant: 'danger' },
    skipped_no_consent: { label: 'Skipped: no marketing consent', variant: 'warning' },
};

export const modeLabel = {
    all: 'Everyone',
    filtered: 'Filtered group',
    selected: 'Chosen people',
};

export const categoryLabel = {
    event: 'Event update',
    marketing: 'Marketing',
};

export function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function formatDateTime(iso) {
    return iso
        ? new Date(iso).toLocaleString('en-ZA', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              timeZone: 'Africa/Johannesburg',
          })
        : '';
}

export function plural(count, one, many) {
    return `${count} ${count === 1 ? one : many}`;
}
