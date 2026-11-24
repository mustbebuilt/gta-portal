import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';

const events = {};
const closed = { open: false };
const alreadyOpen = { open: true };
runInNewContext(readFileSync(new URL('../public/assets/portal-simplification.js', import.meta.url), 'utf8'), {
    window: { addEventListener: (name, callback) => { events[name] = callback; } },
    document: { querySelectorAll: () => [closed, alreadyOpen] },
});
events.beforeprint();
assert.equal(closed.open, true);
assert.equal(alreadyOpen.open, true);
events.beforeprint();
events.afterprint();
assert.equal(closed.open, false);
assert.equal(alreadyOpen.open, true);
events.beforeprint();
events.afterprint();
assert.equal(closed.open, false);
console.log('Print expansion, repeated events and restoration passed');
