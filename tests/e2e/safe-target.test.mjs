import assert from 'node:assert/strict';
import { testBaseUrl } from './safe-target.mjs';
assert.equal(testBaseUrl({}), 'http://127.0.0.1:8000');
assert.equal(testBaseUrl({BASE_URL:'http://localhost:8001'}), 'http://localhost:8001');
for (const BASE_URL of ['https://gta-portal-production-pvjtnz.laravel.cloud','https://example.com','http://localhost.example.com','http://127.0.0.1@example.com','file:///tmp/a','http://localhost/redirect','http://localhost?next=production','http://user:password@localhost']) {
    assert.throws(() => testBaseUrl({BASE_URL}));
}
console.log('10 safe browser-target checks passed');
