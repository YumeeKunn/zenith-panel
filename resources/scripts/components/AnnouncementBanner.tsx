import React from 'react';
import { useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';

/**
 * Zenith backport (v2-style): global announcement banner.
 * Set via: ZENITH_ANNOUNCEMENT env var (php artisan zenith:announce "text")
 */
export default () => {
    const announcement = useStoreState((state: ApplicationStore) => state.settings.data?.announcement);
    const announcementType = useStoreState((state: ApplicationStore) => state.settings.data?.announcementType || 'info');

    if (!announcement) return null;

    const styles: Record<string, string> = {
        info: 'bg-blue-500/10 border-blue-500/40 text-blue-100',
        success: 'bg-green-500/10 border-green-500/40 text-green-100',
        warning: 'bg-yellow-500/10 border-yellow-500/40 text-yellow-100',
        danger: 'bg-red-500/10 border-red-500/40 text-red-100',
    };
    const style = styles[announcementType] || styles.info;

    return (
        <div className={`border-b px-4 py-2 text-center text-sm font-medium ${style}`} id={'AnnouncementBanner'}>
            {announcement}
        </div>
    );
};
