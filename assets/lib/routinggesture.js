const SELECTOR = '[data-routing-endpoint]';

/** Pointer and keyboard gestures stay local until a valid completed route calls commit. */
export class RoutingGesture {
    constructor(svg, valid, commit) {
        this.svg = svg;
        this.valid = valid;
        this.commit = commit;
        this.listeners = [];
        for (const [type, method] of [['pointerdown', 'down'], ['pointermove', 'move'], ['pointerup', 'up'],
            ['pointercancel', 'cancel'], ['lostpointercapture', 'cancel'], ['keydown', 'key']]) {
            const listener = event => this[method](event);
            svg.addEventListener(type, listener);
            this.listeners.push(() => svg.removeEventListener(type, listener));
        }
        this.observer = new MutationObserver(() => {
            if (svg.closest('[hidden], .is-collapsed')) this.cancel();
        });
        for (const node of [svg.closest('.routing'), svg.closest('section[data-page]')]) {
            if (node) this.observer.observe(node, { attributes: true, attributeFilter: ['class', 'hidden'] });
        }
    }

    node(target) { return target?.closest?.(SELECTOR); }
    at(event) { return this.node(document.elementFromPoint(event.clientX, event.clientY)); }

    begin(node) {
        this.cancel();
        this.start = node;
        node.classList.add('is-start');
        node.setAttribute('aria-pressed', 'true');
        this.svg.closest('.routing').classList.add('is-editing');
        this.svg.dispatchEvent(new CustomEvent('routing:editing', { bubbles: true }));
        for (const target of this.svg.querySelectorAll(SELECTOR)) {
            target.classList.toggle('is-valid', Boolean(this.valid(node, target)));
        }
    }

    down(event) {
        const node = this.node(event.target);
        if (!node || node.getAttribute('aria-disabled') === 'true' || !event.isPrimary || event.button !== 0) return;
        event.preventDefault();
        node.focus({ preventScroll: true });
        this.begin(node);
        this.pointer = event.pointerId;
        this.origin = { x: event.clientX, y: event.clientY };
        this.svg.setPointerCapture(event.pointerId);
        this.line = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        this.line.classList.add('routing__preview');
        this.svg.append(this.line);
        this.move(event);
    }

    move(event) {
        if (!this.start || event.pointerId !== this.pointer) return;
        const target = this.at(event);
        this.target?.classList.remove('is-target');
        this.target = target && this.svg.contains(target) && this.valid(this.start, target) ? target : null;
        this.target?.classList.add('is-target');
        const point = this.svg.createSVGPoint();
        point.x = event.clientX; point.y = event.clientY;
        const position = point.matrixTransform(this.svg.getScreenCTM().inverse());
        const box = this.start.getBBox();
        const centre = this.svg.createSVGPoint();
        centre.x = box.x + box.width / 2; centre.y = box.y + box.height / 2;
        const start = centre.matrixTransform(this.svg.getCTM().inverse().multiply(this.start.getCTM()));
        this.line.setAttribute('d', `M${start.x},${start.y} L${position.x},${position.y}`);
    }

    up(event) {
        if (!this.start || event.pointerId !== this.pointer) return;
        this.move(event);
        const start = this.start, target = this.target;
        const moved = Math.hypot(event.clientX - this.origin.x, event.clientY - this.origin.y) >= 5;
        this.cancel();
        if (moved && target) this.commit(start, target);
    }

    key(event) {
        if (event.key === 'Escape') { this.cancel(); return; }
        if (!['Enter', ' '].includes(event.key) || event.repeat) return;
        const node = this.node(event.target);
        if (!node || node.getAttribute('aria-disabled') === 'true') return;
        event.preventDefault();
        if (!this.start) this.begin(node);
        else if (this.valid(this.start, node)) {
            const start = this.start;
            this.cancel();
            this.commit(start, node);
        }
    }

    cancel() {
        if (!this.start && this.pointer == null && !this.line) return;
        const pointer = this.pointer;
        this.pointer = null;
        this.start = this.target = null;
        this.line?.remove(); this.line = null;
        this.svg.closest('.routing')?.classList.remove('is-editing');
        for (const node of this.svg.querySelectorAll(SELECTOR)) {
            node.classList.remove('is-start', 'is-valid', 'is-target');
            node.setAttribute('aria-pressed', 'false');
        }
        if (pointer != null && this.svg.hasPointerCapture(pointer)) this.svg.releasePointerCapture(pointer);
    }

    disconnect() {
        this.cancel();
        this.listeners.forEach(off => off());
        this.observer.disconnect();
    }
}
