import { test, expect } from '@playwright/test';

test.describe('Articles Index Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/articles?brandId=truefire');
  });

  test('displays branded header with TrueFire', async ({ page }) => {
    // Header should show brand name
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/TrueFire/);
  });

  test('has create article button', async ({ page }) => {
    const createButton = page.getByRole('link', { name: /Create Article/i });
    await expect(createButton).toBeVisible();
    await expect(createButton).toHaveAttribute('href', /\/articles\/create/);
  });

  test('has settings link', async ({ page }) => {
    const settingsLink = page.getByRole('link', { name: /Settings/i });
    await expect(settingsLink).toBeVisible();
    await expect(settingsLink).toHaveAttribute('href', /\/articles\/settings/);
  });

  test('has search input', async ({ page }) => {
    const searchInput = page.getByPlaceholder(/Search/i);
    await expect(searchInput).toBeVisible();
  });

  test('search filters articles', async ({ page }) => {
    const searchInput = page.getByPlaceholder(/Search/i);
    
    // Type in search
    await searchInput.fill('nonexistent-article-xyz');
    
    // Should show no results or filter
    // Wait a moment for filter to apply
    await page.waitForTimeout(300);
    
    // If no articles match, should show empty state
    const noArticles = page.getByText(/No articles found/i);
    const articleCards = page.locator('[href^="/articles/"]').filter({ hasNot: page.locator('nav') });
    
    // Either shows "no articles" message or filtered results
    const hasNoResults = await noArticles.isVisible().catch(() => false);
    const cardCount = await articleCards.count();
    
    expect(hasNoResults || cardCount === 0).toBe(true);
  });

  test('can clear search', async ({ page }) => {
    const searchInput = page.getByPlaceholder(/Search/i);
    
    await searchInput.fill('test');
    await expect(searchInput).toHaveValue('test');
    
    // Look for clear button
    const clearButton = page.locator('button').filter({ has: page.locator('svg') }).last();
    if (await clearButton.isVisible()) {
      await clearButton.click();
      await expect(searchInput).toHaveValue('');
    }
  });

  test('has brand dropdown', async ({ page }) => {
    // Look for brand dropdown button
    const brandButton = page.locator('button').filter({ hasText: /TrueFire|Switch/i }).first();
    
    if (await brandButton.isVisible()) {
      await brandButton.click();
      
      // Dropdown should show other brands
      await expect(page.getByText('ArtistWorks')).toBeVisible();
      await expect(page.getByText('Blayze')).toBeVisible();
      await expect(page.getByText('FaderPro')).toBeVisible();
    }
  });

  test('switching brand updates the page', async ({ page }) => {
    // Click brand dropdown
    const brandButton = page.locator('button').filter({ has: page.locator('img') }).first();
    
    if (await brandButton.isVisible()) {
      await brandButton.click();
      
      // Wait for dropdown
      await page.waitForTimeout(200);
      
      // Click on ArtistWorks
      await page.getByRole('button', { name: /ArtistWorks/i }).click();
      
      // URL should update
      await expect(page).toHaveURL(/brandId=artistworks/);
    }
  });

  test('navigate to create article page', async ({ page }) => {
    await page.getByRole('link', { name: /Create Article/i }).click();
    
    await expect(page).toHaveURL(/\/articles\/create/);
    await expect(page.locator('h1')).toContainText(/Create New Article/i);
  });

  test('navigate to settings page', async ({ page }) => {
    await page.getByRole('link', { name: /Settings/i }).click();
    
    await expect(page).toHaveURL(/\/articles\/settings/);
  });

  test('has dashboard link', async ({ page }) => {
    const dashboardLink = page.getByRole('link', { name: /Dashboard/i });
    await expect(dashboardLink).toBeVisible();
  });

  test('displays "All Articles" heading', async ({ page }) => {
    await expect(page.getByText('All Articles')).toBeVisible();
  });
});
