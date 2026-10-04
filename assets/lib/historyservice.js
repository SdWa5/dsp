import { EditHistory } from './history.js';

/** Shared browser session. Injected I/O keeps the state machine independently testable. */
export class HistoryService {
    constructor({ store, queue, api, status, updateStatus, units, report }) {
        Object.assign(this, { store, queue, api, status, updateStatus, report });
        this.history = new EditHistory(units);
        this.generation = 0;
        this.epoch = 0;
        this.failed = false;
        this.replaying = false;
        this.holds = new Set();
        this.offEdit = store.onEdit((path, before, after, state) => {
            if (this.replaying || this.suspended) return;
            this.history.change(path, before, after, state, status.riders);
            this.generation++;
            queueMicrotask(() => { if (!this.holds.size && !this.explicit) { this.history.end(store.state, status.riders); this.schedule(); } });
        });
        this.offQueue = queue.subscribe(({ error }) => { if (error) this.failed = true; });
    }
    begin(label = 'Change setting') { this.explicit = (this.explicit ?? 0) + 1; this.history.begin(label, this.store.state, this.status.riders); }
    end() { this.explicit = Math.max(0, (this.explicit ?? 0) - 1); this.history.end(this.store.state, this.status.riders); this.schedule(); }
    hold(key) {
        if (this.holds.has(key)) return;
        if (!this.holds.size) this.history.begin('Change setting', this.store.state, this.status.riders);
        this.holds.add(key);
    }
    release(key) {
        if (!this.holds.delete(key)) return;
        if (!this.holds.size) { this.history.end(this.store.state, this.status.riders); this.schedule(); }
    }
    wheel(key) {
        if (this.wheelKey && this.wheelKey !== key) this.release(this.wheelKey);
        this.wheelKey = key;
        this.hold(key);
        clearTimeout(this.wheelTimer);
        this.wheelTimer = setTimeout(() => { this.release(key); this.wheelKey = null; }, 500);
    }
    schedule() {
        if (this.holds.size || this.explicit || !this.history.pending.length || this.suspended) return;
        if (this.pump) return;
        this.history.busy = true; this.history.notify();
        const epoch = this.epoch;
        this.pump = this.confirm().catch(error => epoch === this.epoch ? this.recover(error) : undefined).finally(() => {
            this.pump = null; this.history.busy = false; this.history.notify(); this.schedule();
        });
    }
    async settled(epoch = this.epoch) {
        const deadline = Date.now() + 30000;
        while (Date.now() < deadline) {
            if (epoch !== this.epoch) throw new Error('History cleared after disconnecting.');
            if (this.queue.running) await this.queue.running;
            const data = await this.api('status', undefined, 'GET');
            if (epoch !== this.epoch) throw new Error('History cleared after disconnecting.');
            if (!data.connected) throw new Error('History cleared after disconnecting.');
            this.updateStatus(data);
            if (!this.queue.size && !this.queue.running && !data.queued && data.resync == null) return;
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        throw new Error('History could not establish the resulting state.');
    }
    async confirm() {
        const epoch = this.epoch;
        for (;;) {
            const generation = this.generation;
            await this.settled(epoch);
            if (this.holds.size || this.explicit) return;
            const state = await this.api('state', undefined, 'GET');
            if (epoch !== this.epoch) return;
            if (generation !== this.generation) continue;
            if (this.failed) { this.failed = false; throw new Error('History cleared after a failed write.'); }
            this.store.edits.clear(); this.store.replace(state);
            this.history.confirm(state);
            return;
        }
    }
    async recover(error) {
        this.history.clear('History cleared after a failed write.');
        this.report(error.message);
        try { await this.api('refresh'); await this.settled(); this.store.edits.clear(); this.store.replace(await this.api('state', undefined, 'GET')); }
        catch { this.history.clear('History could not establish the resulting state.'); }
    }
    /** Bulk changes serialize behind ordinary writes and diff only the caller's declared live-setting footprint. */
    async run(label, work, { paths = this.history.paths } = {}) {
        if (this.pump) await this.pump;
        if (this.history.busy || this.suspended || this.holds.size) throw new Error('History is waiting for device writes.');
        const epoch = this.epoch;
        this.history.busy = true; this.locked = true; this.history.notify();
        try {
            await this.settled(epoch);
            const before = await this.api('state', undefined, 'GET');
            if (epoch !== this.epoch) return;
            this.store.edits.clear(); this.store.replace(before);
            this.begin(label);
            await work();
            await this.settled(epoch);
            const after = await this.api('state', undefined, 'GET');
            if (epoch !== this.epoch) return;
            if (this.failed) { this.failed = false; throw new Error('History cleared after a failed write.'); }
            this.history.end(after, this.status.riders, true);
            this.explicit = 0;
            const entry = this.history.pending.at(-1);
            if (entry) entry.changes = entry.changes.filter(c => paths.includes(c.path));
            this.store.edits.clear(); this.store.replace(after);
            this.history.confirm(after);
        } catch (error) { if (epoch === this.epoch) await this.recover(error); throw error; }
        finally { this.explicit = 0; this.locked = false; this.history.busy = false; this.history.notify(); }
    }
    async replay(direction) {
        if (this.history.busy || this.history.pending.length || this.holds.size || this.suspended) return;
        this.history.busy = true; this.locked = true; this.history.notify();
        const epoch = this.epoch;
        let posted = false;
        try {
            await this.settled(epoch);
            const state = await this.api('state', undefined, 'GET');
            if (epoch !== this.epoch) return;
            const patch = this.history.patch(direction, state, this.status.riders);
            if (!patch) return;
            this.replaying = true;
            await this.api('history', patch); posted = true;
            await this.settled(epoch);
            const after = await this.api('state', undefined, 'GET');
            if (epoch !== this.epoch) return;
            this.store.edits.clear(); this.store.replace(after);
            this.history.moved(direction);
            this.history.message = direction === 'undo' ? 'Undid the last action.' : 'Redid the last action.';
        } catch (error) {
            if (epoch !== this.epoch) return;
            if (posted || !error.message.startsWith('History conflict') && !error.message.startsWith('History is waiting')) await this.recover(error);
            else { this.history.message = error.message; this.report(error.message); }
        } finally { this.replaying = false; this.locked = false; this.history.busy = false; this.history.notify(); }
    }
    reset(message = '') {
        ++this.epoch;
        clearTimeout(this.wheelTimer); this.wheelKey = null; this.holds.clear(); this.explicit = 0;
        this.history.clear(message);
    }
    disconnect() { this.offEdit(); this.offQueue(); clearTimeout(this.wheelTimer); }
}
