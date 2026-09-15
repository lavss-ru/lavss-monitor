import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import vm from 'node:vm';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

const source = readFileSync('resources/js/Pages/LocalInfrastructure/WireGuardDiagnostics.tsx', 'utf8');
const compiled = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.React, esModuleInterop: true },
}).outputText;
const context = { exports: {}, require: createRequire(import.meta.url) };
vm.runInNewContext(compiled, context);
const Component = context.exports.default;
const defaults = {
    connection_type: 'wireguard', wireguard_interface: 'wg-test', wireguard_diagnostic_state: 'unknown',
    wireguard_last_handshake_at: null, wireguard_rx_bytes: null, wireguard_tx_bytes: null,
};
const render = overrides => renderToStaticMarkup(React.createElement(Component, { ...defaults, ...overrides }));

test('unconfigured interface and non-WireGuard locations render no diagnostic block', () => {
    assert.equal(render({ wireguard_interface: null }), '');
    assert.equal(render({ wireguard_interface: '' }), '');
    assert.equal(render({ connection_type: 'local' }), '');
});

test('unavailable diagnostics are neutral metadata and explain authoritative TCP connectivity', () => {
    const html = render({});
    assert.match(html, /WG-диагностика недоступна/);
    assert.match(html, /Доступность площадки определяется TCP-проверкой/);
    assert.match(html, /wg-test/);
    assert.match(html, /text-slate-400/);
    assert.doesNotMatch(html, /role="alert"|text-(?:red|rose|amber)-|OFFLINE|неисправность/);
});

test('unknown state hides stale details even if they are present in the payload', () => {
    const html = render({ wireguard_last_handshake_at: '2026-09-14T12:00:00Z', wireguard_rx_bytes: 123, wireguard_tx_bytes: 456 });
    assert.doesNotMatch(html, /Handshake:|RX\/TX:/);
});

test('available and stale metadata retain neutral diagnostic details', () => {
    for (const [state, label] of [['fresh', 'свежий handshake'], ['stale', 'давний handshake'], ['never', 'handshake не было']]) {
        const html = render({ wireguard_diagnostic_state: state, wireguard_rx_bytes: 123, wireguard_tx_bytes: 456 });
        assert.ok(html.includes(label));
        assert.match(html, /RX\/TX: 123\/456 B/);
        assert.doesNotMatch(html, /WG-диагностика недоступна|role="alert"|text-(?:red|rose|amber)-/);
    }
});
