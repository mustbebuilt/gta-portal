import { defineConfig } from '@playwright/test';
import { testBaseUrl } from './tests/e2e/safe-target.mjs';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60 * 1000,
  expect: {
    timeout: 10 * 1000,
  },
  use: {
    baseURL: testBaseUrl(),
    screenshot: 'off',
    video: 'off',
    trace: 'off',
    ignoreHTTPSErrors: false,
  },
  reporter: [['list'], ['html', { open: 'never' }]],
});

