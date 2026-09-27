// The device state as the bridge sends it, plus local edits that are newer than the last state event.

export function getPath(object, path) {
    let node = object;
    for (const key of path.split('.')) {
        if (null === node || undefined === node) {
            return undefined;
        }
        node = node[key];
    }
    return node;
}

export function setPath(object, path, value) {
    const keys = path.split('.');
    const last = keys.pop();
    let node = object;
    for (const key of keys) {
        if (null === node[key] || 'object' !== typeof node[key]) {
            node[key] = {};
        }
        node = node[key];
    }
    node[last] = value;
}

export class Store {
    /**
     * @param {number} hold milliseconds a local edit wins over incoming state
     * @param {() => number} clock
     */
    constructor(hold = 1500, clock = () => Date.now()) {
        this.state = null;
        this.hold = hold;
        this.clock = clock;
        this.edits = new Map();
        this.listeners = new Set();
    }

    get(path) {
        return null === this.state ? undefined : getPath(this.state, path);
    }

    /** A local edit: applied at once and kept against older incoming state for a while. */
    set(path, value) {
        if (null === this.state) {
            return;
        }
        setPath(this.state, path, value);
        this.edits.set(path, { value, at: this.clock() });
    }

    /** State from the bridge. Recent local edits are laid over it. */
    replace(state) {
        const now = this.clock();
        for (const [path, edit] of this.edits) {
            if (now - edit.at > this.hold) {
                this.edits.delete(path);
            } else if (null !== state) {
                setPath(state, path, edit.value);
            }
        }
        this.state = state;
        this.notify();
    }

    subscribe(listener) {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    }

    notify() {
        for (const listener of this.listeners) {
            listener(this.state);
        }
    }
}
