import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

// Configuration: uses the live Cloud URL by default
const BASE_URL = process.env.BASE_URL || 'https://gta-portal-production-pvjtnz.laravel.cloud';

// Test credentials (default fictional demo accounts from gta:demo)
const TUTOR_EMAIL = process.env.TUTOR_EMAIL || 'tutor@example.test';
const APPRENTICE_EMAIL = process.env.APPRENTICE_EMAIL || 'apprentice@example.test';
const PASSWORD = process.env.GTA_PASSWORD || ';}8R]/Er9PmyE|DtR5f1Qyt4';

test.describe.serial('Feature 1: Learning Tasks and Portfolio Workflow', () => {

    test('Step 1: Tutor logs in and ensures workshop tasks are allocated', async ({ page }) => {
        // 1. Visit Login Page
        await page.goto(`${BASE_URL}/login`);
        await expect(page).toHaveTitle(/Sign in|GTA/i);

        // 2. Log in as Tutor
        await page.fill('input[name="email"]', TUTOR_EMAIL);
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('button:has-text("Sign in")');

        // Confirm dashboard or topbar visible
        await expect(page.locator('header.topbar')).toContainText(/Tutor/i);

        // 3. Navigate to Apprentice 1's Learning workspace
        await page.goto(`${BASE_URL}/apprentices/1/learning`);

        // 4. Check if tasks need allocation
        const allocateButton = page.locator('button:has-text("Allocate")');
        if (await allocateButton.isVisible()) {
            const confirmCheckbox = page.locator('input[name="confirmed"]');
            if (await confirmCheckbox.isVisible()) {
                await confirmCheckbox.check();
            }
            await allocateButton.click();
            await page.waitForLoadState('networkidle');
        }

        // Verify task table has loaded tasks
        const taskLinks = page.locator('a[href*="/tasks/"]');
        await expect(taskLinks.first()).toBeVisible();
        const taskCount = await taskLinks.count();
        expect(taskCount).toBeGreaterThan(0);
        console.log(`Verified ${taskCount} workshop tasks available for Apprentice 1.`);

        // 5. Sign out
        await page.click('button:has-text("Sign out")');
    });

    test('Step 2: Apprentice uploads evidence and submits task for marking', async ({ page }) => {
        // 1. Log in as Apprentice
        await page.goto(`${BASE_URL}/login`);
        await page.fill('input[name="email"]', APPRENTICE_EMAIL);
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('button:has-text("Sign in")');

        // 2. Open Apprentice Learning Tasks
        await page.goto(`${BASE_URL}/apprentices/1/learning`);

        // Click on the first task link
        const firstTaskLink = page.locator('a[href*="/tasks/"]').first();
        const taskUrl = await firstTaskLink.getAttribute('href');
        await firstTaskLink.click();
        await page.waitForLoadState('networkidle');

        // 3. Upload a sample test evidence file
        const sampleFilePath = path.join(__dirname, 'sample-inspection-evidence.txt');
        if (!fs.existsSync(sampleFilePath)) {
            fs.writeFileSync(sampleFilePath, 'Sample Workshop Inspection Checklist Evidence\nDate: ' + new Date().toISOString());
        }

        const fileInput = page.locator('input[type="file"][name="file"]');
        if (await fileInput.isVisible()) {
            await fileInput.setInputFiles(sampleFilePath);

            // Select evidence kind if dropdown exists
            const kindSelect = page.locator('select[name="kind"]');
            if (await kindSelect.isVisible()) {
                await kindSelect.selectOption({ index: 0 });
            }

            await page.click('button:has-text("Upload")');
            await page.waitForLoadState('networkidle');
            console.log('Sample evidence file uploaded successfully to storage.');
        }

        // 4. Fill in any text input fields inside the task form
        const textInputs = page.locator('form input[type="text"][name^="answers"], form textarea[name^="answers"]');
        const inputCount = await textInputs.count();
        for (let i = 0; i < inputCount; i++) {
            const input = textInputs.nth(i);
            if (await input.isVisible() && (await input.inputValue()) === '') {
                await input.fill('Completed inspection check as required.');
            }
        }

        // 5. Tick "own authentic work" declaration
        const ownWorkCheckbox = page.locator('input[name="own_work"]');
        if (await ownWorkCheckbox.isVisible()) {
            await ownWorkCheckbox.check();
        }

        // 6. Submit task for tutor marking
        const submitButton = page.locator('button:has-text("Submit")');
        if (await submitButton.isVisible()) {
            await submitButton.click();
            await page.waitForLoadState('networkidle');
            console.log('Task submitted for marking.');
        }

        // Assert status changed to Awaiting marking or similar submitted state
        const statusBadge = page.locator('.badge, .status, strong, span');
        await expect(statusBadge.filter({ hasText: /Awaiting marking|Submitted/i }).first()).toBeVisible();

        // 7. Sign out
        await page.click('button:has-text("Sign out")');
    });

    test('Step 3: Tutor inspects evidence and records marking decision', async ({ page }) => {
        // 1. Log in as Tutor
        await page.goto(`${BASE_URL}/login`);
        await page.fill('input[name="email"]', TUTOR_EMAIL);
        await page.fill('input[name="password"]', PASSWORD);
        await page.click('button:has-text("Sign in")');

        // 2. Go to the task
        await page.goto(`${BASE_URL}/apprentices/1/learning`);
        await page.locator('a[href*="/tasks/"]').first().click();
        await page.waitForLoadState('networkidle');

        // 3. Verify private evidence download link is available
        const evidenceDownload = page.locator('a[href*="/evidence/"]');
        if (await evidenceDownload.count() > 0) {
            await expect(evidenceDownload.first()).toBeVisible();
            console.log('Private evidence download link verified.');
        }

        // 4. Fill in Tutor Decision Form
        const decisionSelect = page.locator('select[name="decision"]');
        if (await decisionSelect.isVisible()) {
            await decisionSelect.selectOption('Approved');

            // Set decision date if date input exists
            const dateInput = page.locator('input[type="date"][name="decision_date"]');
            if (await dateInput.isVisible()) {
                const today = new Date().toISOString().split('T')[0];
                await dateInput.fill(today);
            }

            // Confirm checkbox if present
            const confirmBox = page.locator('input[name="confirmed"]');
            if (await confirmBox.isVisible()) {
                await confirmBox.check();
            }

            // Click Record Decision
            await page.click('button:has-text("Record decision")');
            await page.waitForLoadState('networkidle');
            console.log('Tutor decision recorded: Approved.');
        }

        // 5. Assert final Approved status
        const approvedBadge = page.locator('.badge, .status, strong, span').filter({ hasText: /Approved/i }).first();
        await expect(approvedBadge).toBeVisible();

        // Take a final screenshot
        const screenshotPath = path.join(__dirname, 'evidence-task-approved.png');
        await page.screenshot({ path: screenshotPath, fullPage: true });
        console.log(`Captured full verification screenshot: ${screenshotPath}`);
    });
});
