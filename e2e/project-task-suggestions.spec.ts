import { expect, test } from '../playwright/fixtures';
import { PLAYWRIGHT_BASE_URL } from '../playwright/config';
import { createProjectViaApi, createTaskViaApi } from './utils/api';

test.use({ timezoneId: 'UTC' });

test('suggestions only select a project and task after a click', async ({ page, ctx }) => {
    const project = await createProjectViaApi(ctx, { name: 'Customer portal' });
    const task = await createTaskViaApi(ctx, { name: 'Login', project_id: project.id });
    await page.route('**/project-task-suggestions', (route) =>
        route.fulfill({
            json: {
                data: [
                    {
                        project_id: project.id,
                        project_name: project.name,
                        task_id: task.id,
                        task_name: task.name,
                    },
                ],
            },
        })
    );
    await page.goto(PLAYWRIGHT_BASE_URL + '/dashboard');
    const timer = page.getByTestId('dashboard_timer');
    await timer.getByTestId('time_entry_description').fill('Fix OAuth sign-in');
    await timer.getByRole('button', { name: 'No Project', exact: true }).click();
    await expect(page.getByText('Suggested', { exact: true })).toBeVisible();
    const suggestion = page.locator(`[data-project-id="${project.id}"]`);
    await expect(timer.getByRole('button', { name: 'No Project', exact: true })).toBeVisible();
    await suggestion.getByRole('button', { name: /Tasks/ }).click();
    await page.locator(`[data-task-id="${task.id}"]`).click();
    await expect(timer.getByRole('button', { name: /Customer portal.*Login/ })).toBeVisible();
});

test('the normal picker works when suggestions fail', async ({ page, ctx }) => {
    await createProjectViaApi(ctx, { name: 'Customer portal' });
    await page.route('**/project-task-suggestions', (route) =>
        route.fulfill({ status: 503, json: {} })
    );
    await page.goto(PLAYWRIGHT_BASE_URL + '/dashboard');
    const timer = page.getByTestId('dashboard_timer');
    await timer.getByTestId('time_entry_description').fill('Fix OAuth sign-in');
    await timer.getByRole('button', { name: 'No Project', exact: true }).click();
    await page.getByTestId('client_dropdown_search').fill('Customer portal');
    await expect(page.getByText('Suggested', { exact: true })).toHaveCount(0);
    await page.getByRole('option').filter({ hasText: 'Customer portal' }).click();
    await expect(timer.getByRole('button', { name: 'Customer portal', exact: true })).toBeVisible();
});
