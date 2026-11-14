# Playwright E2E Test: Feature 1 (Learning Tasks & Portfolio)

This test automates the complete end-to-end learning task workflow against your live Laravel Cloud deployment:
`https://gta-portal-production-pvjtnz.laravel.cloud`

---

## What the Test Automates

1. **Step 1 (Tutor)**:
   * Logs in as `tutor@example.test`.
   * Opens Apprentice 1's learning workspace (`/apprentices/1/learning`).
   * Verifies/allocates the 50 Autocare workshop tasks.
   * Signs out.

2. **Step 2 (Apprentice)**:
   * Logs in as `apprentice@example.test`.
   * Selects a task from the list.
   * Uploads a sample private evidence document (tests live S3/object storage).
   * Fills in answers and signs the "own authentic work" declaration.
   * Submits the task for tutor marking (`Awaiting marking`).
   * Signs out.

3. **Step 3 (Tutor Decision)**:
   * Logs back in as `tutor@example.test`.
   * Opens the submitted task.
   * Verifies private evidence download link is accessible.
   * Records decision as **Approved** with today's date.
   * Asserts the task badge updates to **Approved**.
   * Captures a full-page screenshot (`evidence-task-approved.png`).

---

## How to Run the Test

### 1. Install Playwright (one-time setup)

In your terminal in the `gta-portal` directory, run:

```bash
npm install -D @playwright/test
npx playwright install chromium
```

### 2. Run the Test Headless

```bash
npx playwright test
```

### 3. Run with a Visible Browser (Headed Mode)

To watch Playwright open Chrome and perform all the clicks, typing, uploads, and marking live on your screen:

```bash
npx playwright test --headed
```

### 4. Customizing the Password or Target URL (Optional)

If your Cloud demo password differs from the default:

```bash
GTA_PASSWORD="your-cloud-password" npx playwright test --headed
```

Or targeting another URL (such as local Herd):

```bash
BASE_URL="http://gta-portal.test" npx playwright test --headed
```

### 5. View Test Report & Screenshots

```bash
npx playwright show-report
```

