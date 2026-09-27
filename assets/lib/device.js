// The editor's connection to the bridge: state store, write queue, event stream and API calls.
// Every controller imports the same instances from here.
import { commandKey, SendQueue } from './queue.js';
import { Store } from './store.js';

export const HEADER = 'X-Trackdsp';

export const store = new Store();
export const status = { connected: false, bridge: false, error: '', target: '' };
const listeners = { status: new Set(), meters: new Set(), error: new Set() };

export function on(event, listener) {
    listeners[event].add(listener);
    return () => listeners[event].delete(listener);
}

function emit(event, data) {
    for (const listener of listeners[event]) {
        listener(data);
    }
}

export class ApiError extends Error {}

export async function api(path, body = undefined, method = 'POST') {
    const options = { method, headers: { [HEADER]: '1', Accept: 'application/json' } };
    if (undefined !== body) {
        options.body = 'string' === typeof body ? body : JSON.stringify(body);
        options.headers['Content-Type'] = 'application/json';
    }
    const response = await fetch(`/api/${path}`, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new ApiError(data.error ?? `HTTP ${response.status}`);
    }
    return data;
}

export const queue = new SendQueue((commands) => api('write', { commands }), (error) => emit('error', error.message));

/**
 * Sends commands in order. Commands with a key replace a queued one with the same key.
 * @param {object[]} commands
 * @param {boolean} merge false for commands that must all arrive (copy, link, presets)
 */
export function send(commands, merge = true) {
    let last;
    for (const command of commands) {
        last = queue.push(command, merge ? commandKey(command) : null);
    }
    return last ?? Promise.resolve();
}

let source = null;

/** Opens the event stream. EventSource reconnects by itself after the stream ends. */
export function listen() {
    if (null !== source) {
        return;
    }
    source = new EventSource('/api/events');
    source.addEventListener('status', (e) => {
        Object.assign(status, { bridge: true }, JSON.parse(e.data));
        if (!status.connected) {
            store.replace(null);
        }
        emit('status', status);
    });
    source.addEventListener('state', (e) => store.replace(JSON.parse(e.data)));
    source.addEventListener('meters', (e) => emit('meters', JSON.parse(e.data).levels));
    source.addEventListener('error', () => {
        status.bridge = false;
        emit('status', status);
    });
}

let labels = null;

/** Value lists from App\Protocol\Labels, rendered into the page. */
export function getLabels() {
    if (null === labels) {
        const node = document.getElementById('trackdsp-labels');
        labels = node ? JSON.parse(node.textContent) : {};
    }
    return labels;
}
