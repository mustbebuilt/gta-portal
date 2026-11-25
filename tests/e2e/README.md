# Local fictional-data browser tests

These tests change tasks, evidence and marking decisions. They refuse every non-loopback host, including the production Cloud portal. Use a disposable local database and disabled outbound mail/SMS. The default target is http://127.0.0.1:8000. Set TUTOR_EMAIL, APPRENTICE_EMAIL and GTA_PASSWORD explicitly for local fictional accounts; no password is supplied in source. BASE_URL may change the loopback port only. Certificate errors are not ignored. Screenshots, traces and videos are off by default.

Run `node tests/e2e/safe-target.test.mjs` before `npm run test:e2e`. Do not weaken the host guard to reach production. A separate authorised live checklist is in the release handover.
