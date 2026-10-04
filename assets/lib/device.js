// The editor's connection to the bridge: state store, write queue, event stream and API calls.
// Every controller imports the same instances from here.
import { commandKey, SendQueue } from './queue.js';
import { Store } from './store.js';
import { HistoryService } from './historyservice.js';
import { historyMessage } from './history.js';

export const HEADER = 'X-Trackdsp';

export const store = new Store();
export const status = { connected: false, bridge: false, error: '', target: '', demo: false, riders: [] };
const listeners = { status: new Set(), meters: new Set(), limiting: new Set(), riders: new Set(), error: new Set() };

/**
 * Listens to status, meters (levels), limiting (bitmask of the outputs whose limiter works, bit 0 = Out1), riders
 * (what the gain riders do right now) or error.
 */
export function on(event, listener) {
    listeners[event].add(listener);
    return () => listeners[event].delete(listener);
}

function emit(event, data) {
    for (const listener of listeners[event]) {
        listener(data);
    }
}

/** Shows a message in the editor's error line. */
export function reportError(message) {
    emit('error', message);
}

/** Takes a bridge status from an API answer, e.g. after starting a gain rider. */
export function updateStatus(data) {
    Object.assign(status, data);
    emit('status', status);
}

export class ApiError extends Error {}

/**
 * Calls the API. body: an object (sent as JSON), a string or a Blob/File (sent as it is).
 */
export async function api(path, body = undefined, method = 'POST') {
    const options = { method, headers: { [HEADER]: '1', Accept: 'application/json' } };
    if (undefined !== body) {
        const raw = 'string' === typeof body || body instanceof Blob;
        options.body = raw ? body : JSON.stringify(body);
        options.headers['Content-Type'] = raw && body instanceof Blob ? 'application/octet-stream' : 'application/json';
    }
    const response = await fetch(`/api/${path}`, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new ApiError(data.error ?? `HTTP ${response.status}`);
    }
    return data;
}

export const queue = new SendQueue((commands) => api('write', { commands }), (error) => emit('error', error.message));

/** Session-only history. The server supplies the editable-field registry used by guarded replay. */
export const history = new HistoryService({ store, queue, api, status, updateStatus,
    units: typeof document === 'undefined' ? [] : JSON.parse(document.getElementById('trackdsp-history')?.textContent ?? '[]'),
    report: message => reportError(historyMessage(message)) });

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
        const data = JSON.parse(e.data);
        if (!data.connected || (status.target && data.target !== status.target)) history.reset('History cleared after disconnecting.');
        Object.assign(status, { bridge: true }, data);
        if (!status.connected) {
            store.replace(null);
        }
        emit('status', status);
    });
    source.addEventListener('state', (e) => store.replace(JSON.parse(e.data)));
    source.addEventListener('meters', (e) => {
        const data = JSON.parse(e.data);
        emit('meters', data.levels);
        emit('limiting', data.limiting ?? 0);
        emit('riders', data.riders ?? []);
    });
    source.addEventListener('error', () => {
        status.bridge = false;
        emit('status', status);
    });
}

let labels = null;
let defaults = null;

/** Value lists from App\Protocol\Labels, rendered into the page. */
export function getLabels() {
    if (null === labels) {
        const node = document.getElementById('trackdsp-labels');
        labels = node ? JSON.parse(node.textContent) : {};
    }
    return labels;
}

/** Factory defaults in the shape of the state (App\Model\FactoryDefaults), rendered into the page. */
export function getDefaults() {
    if (null === defaults) {
        const node = document.getElementById('trackdsp-defaults');
        defaults = node ? JSON.parse(node.textContent) : {};
    }
    return defaults;
}
