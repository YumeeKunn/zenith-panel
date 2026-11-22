import { useEffect, useRef } from 'react';
import { http } from '@pterodactyl/sdk/api';

/**
 * dashboard.serverRow.before slot — receives { data: SdkServer }.
 * Applies the configured background image to the server row card (DOM, like the original).
 */
export default function ServerRowBackground({ data }: { data?: any }) {
    const ref = useRef<HTMLSpanElement | null>(null);

    useEffect(() => {
        const identifier = data?.attributes?.identifier;
        if (!identifier || !ref.current) return;
        let cancelled = false;

        http.get(`/api/client/servers/${identifier}/extensions/serverbackgrounds/`)
            .then(({ data: res }) => {
                if (cancelled || !res?.url) return;
                // climb from our placeholder to the card ancestor
                let el: HTMLElement | null = ref.current?.parentElement || null;
                let card: HTMLElement | null = null;
                while (el && el !== document.body) {
                    const h = el.getBoundingClientRect().height;
                    if (h > 60 && h < 400) card = el;
                    el = el.parentElement;
                }
                if (card) {
                    card.style.backgroundImage = `linear-gradient(rgba(15,17,23,0.72), rgba(15,17,23,0.88)), url('${res.url}')`;
                    card.style.backgroundSize = 'cover';
                    card.style.backgroundPosition = 'center';
                }
            })
            .catch(() => {});
        return () => { cancelled = true; };
    }, [data?.attributes?.identifier]);

    return <span ref={ref} style={{ display: 'none' }} />;
}
