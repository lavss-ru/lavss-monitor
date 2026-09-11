import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

// Use the existing TypeScript compiler and Node runner; no browser/test stack.
const source = readFileSync('resources/js/Pages/LocalInfrastructure/locationsPolling.ts', 'utf8');
const compiled = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText;

function setup(hidden = false) {
    const timers = new Map();
    const listeners = new Map();
    const events = new Map();
    const requests = [];
    let nextId = 0;
    const document = {
        hidden,
        addEventListener: (name, fn) => listeners.set(name, fn),
        removeEventListener: (name, fn) => {
            assert.equal(listeners.get(name), fn);
            listeners.delete(name);
        },
    };
    const router = {
        on: (name, fn) => {
            events.set(name, fn);
            return () => events.delete(name);
        },
        reload: options => {
            const request = { options, cancelled: false };
            requests.push(request);
            options.onCancelToken({ cancel: () => {
                request.cancelled = true;
                options.onFinish();
            } });
        },
    };
    const context = {
        exports: {}, require: () => ({ router }), document,
        setInterval: (fn, delay) => {
            assert.equal(delay, 15_000);
            timers.set(++nextId, fn);
            return nextId;
        },
        clearInterval: id => timers.delete(id),
    };
    vm.runInNewContext(compiled, context);
    const start = context.exports.startLocationsPolling;
    const cleanup = start();
    return {
        timers, listeners, events, requests, start, cleanup,
        tick: () => [...timers.values()].forEach(fn => fn()),
        visibility: hidden => {
            document.hidden = hidden;
            listeners.get('visibilitychange')?.();
        },
        emit: (name, visit) => events.get(name)?.({ detail: { visit } }),
    };
}

test('polls only locations silently and waits for completion before next request', () => {
    const h = setup();
    assert.equal(h.timers.size, 1);
    assert.equal(h.requests.length, 0);
    h.tick();
    assert.equal(JSON.stringify(h.requests[0].options.only), '["locations"]');
    assert.equal(h.requests[0].options.showProgress, false);
    h.tick();
    h.tick();
    assert.equal(h.requests.length, 1);
    h.requests[0].options.onFinish();
    h.tick();
    assert.equal(h.requests.length, 2);
    h.cleanup();
});

test('hidden mount has no timer; becoming visible reloads immediately with one timer', () => {
    const h = setup(true);
    assert.equal(h.timers.size, 0);
    h.visibility(false);
    assert.equal(h.requests.length, 1);
    assert.equal(h.timers.size, 1);
    h.visibility(false);
    assert.equal(h.requests.length, 1);
    assert.equal(h.timers.size, 1);
    h.visibility(true);
    assert.equal(h.timers.size, 0);
    h.requests[0].options.onFinish();
    h.tick();
    assert.equal(h.requests.length, 1);
    h.visibility(false);
    assert.equal(h.requests.length, 2);
    h.cleanup();
});

test('foreground CRUD cancels pending poll and blocks polling until finish', () => {
    const h = setup();
    h.tick();
    const visit = { async: false };
    h.emit('start', visit);
    assert.equal(h.requests[0].cancelled, true);
    h.tick();
    h.visibility(false);
    assert.equal(h.requests.length, 1);
    h.emit('finish', visit);
    h.tick();
    assert.equal(h.requests.length, 2);
    h.cleanup();
});

test('async polling events do not cancel their own request or block future polls', () => {
    const h = setup();
    h.tick();
    const visit = { async: true };
    h.emit('start', visit);
    assert.equal(h.requests[0].cancelled, false);
    h.emit('finish', visit);
    h.requests[0].options.onFinish();
    h.tick();
    assert.equal(h.requests.length, 2);
    h.cleanup();
});

test('cleanup cancels request, removes timer/listeners and stale callbacks cannot reload', () => {
    const h = setup();
    const staleTick = [...h.timers.values()][0];
    h.tick();
    h.cleanup();
    assert.equal(h.requests[0].cancelled, true);
    assert.equal(h.timers.size, 0);
    assert.equal(h.listeners.size, 0);
    assert.equal(h.events.size, 0);
    staleTick();
    assert.equal(h.requests.length, 1);
    const cleanupAgain = h.start();
    assert.equal(h.timers.size, 1);
    h.tick();
    assert.equal(h.requests.length, 2);
    cleanupAgain();
    assert.equal(h.timers.size, 0);
    assert.equal(h.events.size, 0);
});
