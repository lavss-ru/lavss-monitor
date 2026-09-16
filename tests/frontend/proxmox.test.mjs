import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import vm from 'node:vm';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

const require = createRequire(import.meta.url);
function compile(path, mocks = {}) {
    const source = readFileSync(path, 'utf8');
    const code = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.React, esModuleInterop: true },
    }).outputText;
    const context = { exports: {}, require: name => mocks[name] ?? require(name) };
    vm.runInNewContext(code, context);
    return context.exports;
}
const inventory = compile('resources/js/Pages/Proxmox/Inventory.tsx');
const metrics = { cpu_usage: 0.25, memory_used: 1024, memory_total: 4096, uptime_seconds: 90061, max_cpu: 4 };
const nodes = [{ ...metrics, id: 1, node_name: 'pve-a', status: 'online', stale: false }, { ...metrics, id: 2, node_name: 'pve-b', status: 'offline', stale: false }];
const guests = [
    { ...metrics, id: 1, proxmox_node_id: 2, guest_type: 'qemu', vmid: 100, name: 'VM-B', status: 'running', template: false, stale: false, disk_used: 0, disk_total: 4096 },
    { ...metrics, id: 2, proxmox_node_id: 1, guest_type: 'lxc', vmid: 101, name: 'CT-A', status: 'stopped', template: true, stale: true, disk_used: null, disk_total: null },
];
const render = props => renderToStaticMarkup(React.createElement(inventory.default, { nodes, guests, available: true, ...props }));

test('Proxmox inventory groups guests under their node and labels templates and stale rows', () => {
    const html = render();
    assert.ok(html.indexOf('pve-a') < html.indexOf('CT-A'));
    assert.ok(html.indexOf('CT-A') < html.indexOf('pve-b'));
    assert.ok(html.indexOf('pve-b') < html.indexOf('VM-B'));
    assert.match(html, /template/); assert.match(html, /stale/);
    assert.match(html, /25.0%/); assert.match(html, /1д 1ч 1м/);
});

test('Proxmox cached metrics remain labeled but unavailable inventory is unknown', () => {
    const html = render({ available: false });
    assert.doesNotMatch(html, />running<|>online<|>stopped</);
    assert.match(html, /unknown/); assert.match(html, /VM-B/);
});

test('Proxmox handles empty inventory, orphan guests and nullable metrics', () => {
    assert.match(render({ nodes: [], guests: [] }), /Inventory пока пуст/);
    assert.match(render({ nodes: [] }), /Без node/);
    assert.equal(inventory.bytes(null), '—');
    assert.equal(inventory.cpu(0), '0.0%');
    assert.equal(inventory.memory(0, 0), '0 B / 0 B');
    assert.equal(inventory.uptime(null), '—');
});

let formData;
let finish;
const page = compile('resources/js/Pages/Proxmox/Index.tsx', {
    './Inventory': inventory,
    '@/Components/Dashboard/Sidebar': { Sidebar: () => null },
    '@headlessui/react': { Dialog: ({ children }) => React.createElement('div', {}, children), DialogPanel: 'div', DialogTitle: 'h2' },
    '@inertiajs/react': {
        Head: () => null, router: {},
        useForm: data => {
            formData = data;
            return { data, errors: {}, processing: false, setData: (key, value) => { formData[key] = value; },
                put: (_url, options) => { finish = options.onFinish; }, post: (_url, options) => { finish = options.onFinish; } };
        },
    },
});
const connection = { id: 1, name: 'Integration', location_id: 1, location: { id: 1, name: 'Home', enabled: true },
    host: 'pve.test', port: 8006, scheme: 'https', verify_tls: true, enabled: true, status: 'online',
    api_user: 'monitor@pve', api_token_id: 'inventory', api_token_secret: 'must-not-be-copied',
    nodes, guests, last_checked_at: null, last_synced_at: null, last_response_ms: null };
const locations = [connection.location];

test('Proxmox editor never initializes stored secret and clears replacement after submit', () => {
    assert.equal(page.initialForm(connection, locations).api_token_secret, '');
    const tree = page.Editor({ connection, locations, close() {} });
    const html = renderToStaticMarkup(tree);
    assert.match(html, /type="password"/); assert.match(html, /autoComplete="new-password"/);
    assert.match(html, /Пустое поле сохраняет/); assert.doesNotMatch(html, /must-not-be-copied/);
    function findForm(element) {
        if (!element || typeof element !== 'object') return null;
        if (element.type === 'form') return element;
        for (const child of React.Children.toArray(element.props?.children)) {
            const found = findForm(child); if (found) return found;
        }
        return null;
    }
    formData.api_token_secret = 'replacement';
    findForm(tree).props.onSubmit({ preventDefault() {} });
    finish();
    assert.equal(formData.api_token_secret, '');
});

test('Proxmox page renders connection actions local snapshot and secure TLS default', () => {
    const html = renderToStaticMarkup(React.createElement(page.default, { connections: [connection], locations, result: null }));
    assert.match(html, /Проверить подключение/); assert.match(html, /Синхронизировать/);
    assert.match(html, /TLS: проверка включена/); assert.match(html, /Snapshot/);
    assert.doesNotMatch(html, /must-not-be-copied/);
    assert.equal(page.initialForm(undefined, locations).verify_tls, true);
    assert.equal(page.initialForm(undefined, locations).scheme, 'https');
});

test('Proxmox page explains safe API error codes alongside unknown or offline status', () => {
    for (const [code, status, message] of [
        ['auth', 'unknown', 'Ошибка авторизации API'],
        ['network', 'offline', 'Ошибка подключения'],
        ['http', 'offline', 'Ошибка HTTP API'],
        ['payload', 'unknown', 'Некорректный ответ API'],
        ['internal', 'unknown', 'Внутренняя ошибка'],
        ['location_unavailable', 'unknown', 'Площадка недоступна'],
    ]) {
        const html = renderToStaticMarkup(React.createElement(page.default, {
            connections: [{ ...connection, status, last_error_code: code }], locations, result: null,
        }));
        assert.ok(html.includes(message));
        assert.ok(html.includes(status));
        assert.doesNotMatch(html, /must-not-be-copied/);
    }
});
