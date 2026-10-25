import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';

const baseUrl = (__ENV.BASE_URL || '').replace(/\/+$/, '');
const tenant = (__ENV.POS_TENANT || '').trim();
const sessionCookie = __ENV.SESSION_COOKIE || 'comerciosrd-session';
const maxVUs = Number(__ENV.MAX_VUS || 100);
const stepDuration = __ENV.STEP_DURATION || '2m';
const holdDuration = __ENV.HOLD_DURATION || '10m';
const thinkTime = Number(__ENV.THINK_TIME_SECONDS || 10);
const searchTerm = encodeURIComponent(__ENV.SEARCH_TERM || 'aceite');

if (__ENV.LOAD_ENV !== 'staging' || __ENV.CONFIRM_STAGING_LOAD !== 'YES') {
    throw new Error('Carga bloqueada: configura LOAD_ENV=staging y CONFIRM_STAGING_LOAD=YES.');
}

if (!/^https:\/\/[^/]*staging[^/]*(\/|$)/i.test(baseUrl)) {
    throw new Error('BASE_URL debe ser HTTPS y tener "staging" en el hostname.');
}

if (!tenant || !Number.isInteger(maxVUs) || maxVUs < 1 || maxVUs > 10000) {
    throw new Error('POS_TENANT requerido y MAX_VUS debe estar entre 1 y 10000.');
}

if (maxVUs > 500 && __ENV.CONFIRM_10K_LOAD !== 'YES') {
    throw new Error('Para MAX_VUS > 500 confirma staging con CONFIRM_10K_LOAD=YES.');
}

if (!__ENV.SESSION_POOL) {
    throw new Error('SESSION_POOL debe apuntar a un JSON local con una cookie de sesión por usuario de prueba.');
}

const sessions = new SharedArray('unique authenticated sessions', () => {
    const rows = JSON.parse(open(__ENV.SESSION_POOL));

    if (!Array.isArray(rows)) {
        throw new Error('SESSION_POOL debe ser un arreglo JSON.');
    }

    return rows;
});

const stages = [0.1, 0.25, 0.5, 0.75, 1].map((fraction) => ({
    duration: stepDuration,
    target: Math.max(1, Math.ceil(maxVUs * fraction)),
}));

export const options = {
    scenarios: {
        pos_read_only: {
            executor: 'ramping-vus',
            startVUs: 1,
            stages: [...stages, { duration: holdDuration, target: maxVUs }],
            gracefulRampDown: '30s',
            gracefulStop: '30s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1000', 'p(99)<2000'],
        checks: ['rate>0.99'],
    },
};

export function setup() {
    if (sessions.length < maxVUs) {
        throw new Error(`Se necesitan ${maxVUs} sesiones; SESSION_POOL tiene ${sessions.length}.`);
    }

    const uniqueCookies = new Set(sessions.map((row) => row.cookie));
    const uniqueUsers = new Set(sessions.map((row) => row.user_id));
    if (
        uniqueCookies.size < maxVUs ||
        uniqueUsers.size < maxVUs ||
        sessions.some((row) => typeof row.cookie !== 'string' || row.cookie.length < 20 || !row.user_id)
    ) {
        throw new Error('SESSION_POOL necesita una cookie y un usuario de prueba únicos por cada VU.');
    }

    return { sessionCount: sessions.length };
}

export default function () {
    const session = sessions[(__VU - 1) % sessions.length];
    const headers = {
        Accept: 'application/json',
        Cookie: `${sessionCookie}=${session.cookie}`,
        'X-Requested-With': 'XMLHttpRequest',
    };
    const root = `${baseUrl}/caja/${encodeURIComponent(tenant)}/api`;

    if (__ITER === 0) {
        const bootstrap = http.get(`${root}/bootstrap`, { headers, tags: { endpoint: 'pos_bootstrap' } });
        check(bootstrap, {
            'POS bootstrap HTTP 200': (response) => response.status === 200,
            'POS bootstrap JSON': (response) => response.headers['Content-Type']?.includes('application/json'),
        });
    }

    const search = http.get(`${root}/search?q=${searchTerm}`, {
        headers,
        tags: { endpoint: 'pos_search' },
    });
    check(search, {
        'POS search HTTP 200': (response) => response.status === 200,
        'POS search JSON ok': (response) => {
            if (response.status !== 200) return false;
            try {
                return JSON.parse(response.body).ok === true;
            } catch {
                return false;
            }
        },
    });

    sleep(thinkTime);
}
