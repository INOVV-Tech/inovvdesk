const { test, expect } = require('@playwright/test');

const ADMIN = { email: 'admin@test.com', password: 'Admin@123456' };

async function login(page) {
  await page.goto('/index.php?page=login');
  await page.locator('input[name="email"]').fill(ADMIN.email);
  await page.locator('input[name="password"]').fill(ADMIN.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/\?page=(work|dashboard)/);
}

test.describe('Project flows (local debug v2)', () => {
  test('big modal create, rich text, delete, d/m/Y chip', async ({ page }) => {
    page.on('dialog', d => d.accept());
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page);

    await page.goto('/index.php?page=projects');
    await page.locator('[data-project-action="board-open-create"]').first().click();
    await page.locator('#project-board-name-input').fill('Board V2');
    await page.locator('[data-project-action="board-save"]').click();
    await page.waitForURL(/page=project&board_id=\d+/);

    await page.locator('.project-list-composer-input').fill('Backlog');
    await page.locator('[data-project-action="list-create"]').click();
    await expect(page.locator('.project-column').first()).toBeVisible();
    await page.waitForLoadState('networkidle');

    // --- "Add card" must open the BIG (detail) modal in create mode ---
    await page.locator('[data-project-action="card-open-create"]').first().click();
    const detailModal = page.locator('#project-card-detail-modal');
    await expect(detailModal).toBeVisible();
    await expect(page.locator('#project-card-detail-modal [data-project-card-detail-title]')).toHaveText(/Novo card/i);
    const smallModalCount = await page.locator('#project-card-modal:not(.hidden)').count();
    console.log('BIG modal used for create (small composer hidden):', smallModalCount === 0);

    // --- Create: title + rich description + assignee + due ---
    await page.locator('#project-card-detail-title-input').fill('Card V2');
    const editor = page.locator('#project-card-description-editor .ql-editor');
    await expect(editor).toBeVisible();
    await editor.click();
    await page.keyboard.type('Desc com ');
    await page.locator('.ql-bold').click();
    await page.keyboard.type('negrito');
    await page.locator('.ql-bold').click();
    await page.locator('.ql-italic').click();
    await page.keyboard.type(' italico');
    await page.locator('.ql-italic').click();
    await page.locator('#project-card-detail-modal [data-project-card-assignee-select]').selectOption({ index: 1 });
    await page.locator('#project-card-detail-due-input').evaluate((el, v) => { el.value = v; }, '2026-08-12T10:00');
    await page.locator('[data-project-action="card-detail-save"]').click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);

    const card = page.locator('.project-card[data-project-card-id]').first();
    await expect(card).toBeVisible();
    const cardId = await card.getAttribute('data-project-card-id');
    console.log('CARD created via big modal, id =', cardId);

    // --- Due chip must be d/m/Y H:i ---
    const dueText = (await card.locator('.project-card-due').first().textContent()).trim();
    console.log('BOARD due chip:', JSON.stringify(dueText));
    expect(dueText).toBe('12/08/2026 10:00');

    // --- Board preview must show rich text (bold/italic) ---
    const boardPreview = card.locator('.project-card-description').first();
    await expect(boardPreview).toBeVisible();
    const boardHtml = await boardPreview.innerHTML();
    console.log('BOARD desc innerHTML:', boardHtml);
    expect(boardHtml).toContain('<strong>');
    expect(boardHtml).toContain('<em>');

    // --- Open detail: modal preview must show rich text too ---
    await card.click();
    await expect(detailModal).toBeVisible();
    await page.waitForTimeout(600);
    const modalPreview = await page.locator('#project-card-detail-modal [data-project-card-detail-description]').innerHTML();
    console.log('MODAL preview innerHTML:', modalPreview);
    expect(modalPreview).toContain('<strong>');
    expect(modalPreview).toContain('<em>');

    // --- Delete button exists in detail modal ---
    const deleteBtn = page.locator('#project-card-detail-modal [data-project-action="card-detail-delete"]');
    await expect(deleteBtn).toBeVisible();
    await deleteBtn.click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
    const remaining = await page.locator('.project-card[data-project-card-id]').count();
    console.log('Cards after delete:', remaining);
    expect(remaining).toBe(0);
  });
});
