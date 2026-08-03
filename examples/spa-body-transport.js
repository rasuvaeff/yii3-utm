const STORAGE_KEY = 'rasuvaeff:yii3-utm';
const VERSION = 1;
const UTM_KEYS = [
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'utm_term',
    'utm_content',
    'utm_id',
];
const CLICK_ID_KEYS = [
    'gclid',
    'gbraid',
    'wbraid',
    'fbclid',
    'yclid',
    'ttclid',
    'msclkid',
    'li_fat_id',
    'twclid',
];

export function captureUtm(location = window.location, storage = window.localStorage) {
    const params = new URLSearchParams(location.search);
    const touchpoint = {
        click_ids: {},
        landing_page: location.href,
        occurred_at: Math.floor(Date.now() / 1000),
    };

    for (const key of UTM_KEYS) {
        const value = params.get(key);

        if (value) {
            touchpoint[key] = value;
        }
    }

    for (const key of CLICK_ID_KEYS) {
        const value = params.get(key);

        if (value) {
            touchpoint.click_ids[key] = value;
        }
    }

    if (document.referrer) {
        touchpoint.referrer = document.referrer;
    }

    const hasCampaign = UTM_KEYS.some((key) => key in touchpoint);
    const hasClickId = Object.keys(touchpoint.click_ids).length > 0;

    if (hasCampaign || hasClickId) {
        storage.setItem(STORAGE_KEY, JSON.stringify({v: VERSION, touchpoint}));
    }
}

export function utmRequestBody(storage = window.localStorage) {
    try {
        const stored = JSON.parse(storage.getItem(STORAGE_KEY) ?? 'null');

        return stored?.v === VERSION && stored.touchpoint
            ? {utm: stored.touchpoint}
            : {};
    } catch {
        return {};
    }
}
