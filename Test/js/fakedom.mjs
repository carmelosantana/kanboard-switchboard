class Target {
    listeners = new Map();

    addEventListener(type, listener) {
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    dispatchEvent(event) {
        event.target ??= this;
        for (const listener of this.listeners.get(event.type) ?? []) {
            listener(event);
        }
        if (event.bubbles && !event.propagationStopped) {
            this.parentNode?.dispatchEvent(event);
        }
        return !event.defaultPrevented;
    }
}

export class FakeEvent {
    constructor(type, options = {}) {
        Object.assign(this, { type, bubbles: false, defaultPrevented: false, propagationStopped: false }, options);
    }

    preventDefault() {
        this.defaultPrevented = true;
    }

    stopPropagation() {
        this.propagationStopped = true;
    }
}

class Element extends Target {
    attributes = new Map();
    children = [];
    dataset = {};
    value = '';
    open = false;
    modalCalls = 0;
    text = '';

    constructor(tag, doc) {
        super();
        this.tagName = tag.toUpperCase();
        this.ownerDocument = doc;
    }

    get id() { return this.getAttribute('id') ?? ''; }
    set id(value) { this.setAttribute('id', value); }

    get textContent() {
        return this.text + this.children.map(child => child.textContent).join('');
    }

    set textContent(value) {
        this.replaceChildren();
        this.text = String(value);
    }

    set innerHTML(value) {
        throw new Error('Use DOM nodes and textContent');
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
        if (name.startsWith('data-')) {
            const key = name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
            this.dataset[key] = String(value);
        }
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    append(...children) {
        for (const child of children) {
            this.insertBefore(child, null);
        }
    }

    insertBefore(child, reference) {
        const index = reference === null ? this.children.length : this.children.indexOf(reference);
        if (index < 0) {
            throw new Error('Reference must be a child');
        }
        this.children.splice(index, 0, child);
        child.parentNode = this;
    }

    replaceChildren(...children) {
        for (const child of this.children) {
            child.parentNode = null;
        }
        this.children = [];
        this.text = '';
        this.append(...children);
    }

    matches(selector) {
        const [, tag, id, cls, attr, value] =
            /^([\w-]+)?(?:#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:=["']?([\w-]+)["']?)?\])?$/.exec(selector) ?? [];
        return (!tag || this.tagName === tag.toUpperCase())
            && (!id || this.id === id)
            && (!cls || this.getAttribute('class')?.split(' ').includes(cls))
            && (!attr || (value ? this.getAttribute(attr) === value : this.attributes.has(attr)));
    }

    querySelectorAll(selector) {
        const [first, ...rest] = selector.split(' ');
        return this.children.flatMap(child => [
            ...(child.matches(first) ? (rest.length ? child.querySelectorAll(rest.join(' ')) : [child]) : []),
            ...child.querySelectorAll(selector),
        ]);
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }

    focus() { this.ownerDocument.activeElement = this; }
    scrollIntoView() {}

    showModal() {
        this.modalCalls++;
        this.open = true;
    }

    close() {
        this.open = false;
        this.dispatchEvent(new FakeEvent('close'));
    }
}

export function fakeDocument() {
    const doc = new Target();
    doc.createElement = tag => new Element(tag, doc);
    doc.documentElement = doc.createElement('html');
    doc.documentElement.parentNode = doc;
    doc.body = doc.createElement('body');
    doc.documentElement.append(doc.body);
    doc.activeElement = doc.body;
    doc.querySelectorAll = selector => doc.documentElement.querySelectorAll(selector);
    doc.querySelector = selector => doc.documentElement.querySelector(selector);
    doc.getElementById = id => doc.querySelector('#' + id);
    return doc;
}

export function add(parent, tag, attributes = {}) {
    const node = parent.ownerDocument.createElement(tag);
    for (const [name, value] of Object.entries(attributes)) {
        node.setAttribute(name, value);
    }
    parent.append(node);
    return node;
}

export function fakeTimers() {
    const pending = new Map();
    let now = 0;
    let nextId = 0;
    return {
        setTimeout(fn, ms) {
            pending.set(++nextId, { fn, at: now + ms });
            return nextId;
        },
        clearTimeout(id) { pending.delete(id); },
        advance(ms) {
            const end = now + ms;
            while (pending.size) {
                const [id, timer] = [...pending].sort((a, b) => a[1].at - b[1].at)[0];
                if (timer.at > end) {
                    break;
                }
                now = timer.at;
                pending.delete(id);
                timer.fn();
            }
            now = end;
        },
    };
}
