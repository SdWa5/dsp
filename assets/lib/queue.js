// Writes to the API: one request in flight, repeated writes to the same key merged while they wait.

export class SendQueue {
    /**
     * @param {(commands: object[]) => Promise<void>} post sends a batch of {command, args}
     * @param {(error: Error) => void} onError
     */
    constructor(post, onError = () => {}) {
        this.post = post;
        this.onError = onError;
        this.pending = new Map();
        this.running = null;
        this.serial = 0;
        this.barrier = 0;
        this.outcomes = new Set();
    }

    /**
     * Queues a command. Commands with the same key replace each other. A null key is never merged,
     * and no later command merges into one queued before it, so the order around it holds.
     */
    push(command, key = null) {
        if (null === key) {
            this.barrier = ++this.serial;
            this.pending.set(`#${this.serial}`, command);
        } else {
            this.pending.set(`${this.barrier}|${key}`, command);
        }
        return this.flush();
    }

    /** Resolves when everything queued so far has been sent. */
    flush() {
        if (null === this.running) {
            this.running = this.run().finally(() => {
                this.running = null;
                if (this.pending.size) this.flush();
            });
        }
        return this.running;
    }

    async run() {
        while (this.pending.size > 0) {
            const batch = [...this.pending.values()];
            this.pending.clear();
            try {
                const result = await this.post(batch);
                this.outcomes.forEach(fn => fn({ batch, result, error: null }));
            } catch (error) {
                this.onError(error);
                this.outcomes.forEach(fn => fn({ batch, error }));
            }
        }
    }

    subscribe(fn) { this.outcomes.add(fn); return () => this.outcomes.delete(fn); }

    get size() {
        return this.pending.size;
    }
}

/** The merge key of a command: what it sets, not the value. */
export function commandKey(command) {
    const args = command.args ?? {};
    const target = ['channel', 'band', 'index', 'io'].filter((k) => k in args).map((k) => `${k}=${args[k]}`);
    return `${command.command}:${target.join(',')}`;
}
