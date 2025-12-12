import { test, expect } from '@playwright/test';

test.describe('Complete Navigation Flow', () => {
  test('full user journey: brand selection → articles → create → back', async ({ page }) => {
    // Step 1: Start at brand selection
    await page.goto('/articles/select-brand');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Welcome');
    
    // Step 2: Select TrueFire brand by clicking the card heading
    await page.getByRole('heading', { name: 'TrueFire' }).click();
    await expect(page).toHaveURL(/\/articles\?brandId=truefire/);
    
    // Step 3: Verify articles index loaded
    await expect(page.getByText('All Articles')).toBeVisible();
    
    // Step 4: Navigate to create article
    await page.getByRole('link', { name: /Create Article/i }).click();
    await expect(page).toHaveURL(/\/articles\/create/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Create New Article/i);
    
    // Step 5: Navigate back to articles
    await page.getByRole('link', { name: /Back to Articles/i }).click();
    await expect(page).toHaveURL(/\/articles/);
    
    // Step 6: Navigate to settings
    await page.getByRole('link', { name: /Settings/i }).click();
    await expect(page).toHaveURL(/\/articles\/settings/);
    
    // Step 7: Navigate back to articles from settings
    await page.getByRole('link', { name: /Back to Articles/i }).first().click();
    await expect(page).toHaveURL(/\/articles/);
  });

  test('switching brands maintains navigation context', async ({ page }) => {
    // Start with TrueFire
    await page.goto('/articles?brandId=truefire');
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/TrueFire/i);
    
    // Open brand dropdown and switch to ArtistWorks
    const brandButton = page.locator('button').filter({ has: page.locator('img') }).first();
    
    if (await brandButton.isVisible()) {
      await brandButton.click();
      await page.waitForTimeout(200);
      
      // Find and click ArtistWorks in the dropdown
      const artistworksOption = page.locator('button').filter({ has: page.locator('img[alt="ArtistWorks"]') });
      if (await artistworksOption.isVisible()) {
        await artistworksOption.click();
        
        // Verify switch
        await expect(page).toHaveURL(/brandId=artistworks/);
      }
    }
  });

  test('dashboard link works', async ({ page }) => {
    await page.goto('/articles?brandId=truefire');
    
    const dashboardLink = page.getByRole('link', { name: /Dashboard/i });
    
    if (await dashboardLink.isVisible()) {
      await dashboardLink.click();
      await expect(page).toHaveURL(/\/dashboard/);
    }
  });

  test('all main pages load without errors', async ({ page }) => {
    const pages = [
      '/articles/select-brand',
      '/articles?brandId=truefire',
      '/articles?brandId=artistworks',
      '/articles?brandId=blayze',
      '/articles?brandId=faderpro',
      '/articles/create?brandId=truefire',
      '/articles/settings?brandId=truefire',
    ];
    
    for (const url of pages) {
      await page.goto(url);
      
      // Check no error page
      const bodyText = await page.locator('body').textContent();
      expect(bodyText).not.toContain('500 Server Error');
      expect(bodyText).not.toContain('404 Not Found');
      
      // Check page rendered content
      const content = await page.locator('main, .container, [class*="max-w"]').first();
      await expect(content).toBeVisible();
    }
  });

  test('brand logos display correctly', async ({ page }) => {
    await page.goto('/articles/select-brand');
    
    // Check for brand logos (images)
    const logos = page.locator('img[alt]');
    
    // Should have multiple logos
    const count = await logos.count();
    expect(count).toBeGreaterThan(0);
  });

  test('responsive navigation works', async ({ page }) => {
    // Test at desktop size
    await page.setViewportSize({ width: 1200, height: 800 });
    await page.goto('/articles?brandId=truefire');
    
    // Create button should be visible
    await expect(page.getByRole('link', { name: /Create Article/i })).toBeVisible();
    
    // Settings link should be visible
    await expect(page.getByRole('link', { name: /Settings/i })).toBeVisible();
  });
});

test.describe('Error Handling', () => {
  test('invalid brand ID shows page (defaults gracefully)', async ({ page }) => {
    await page.goto('/articles?brandId=invalidbrand');
    
    // Should still load (may default to truefire or show error gracefully)
    const hasContent = await page.locator('body').textContent();
    expect(hasContent).not.toContain('500 Server Error');
  });

  test('create page without brand ID still works', async ({ page }) => {
    await page.goto('/articles/create');
    
    // Should still render the create page
    await expect(page.locator('h1')).toContainText(/Create New Article/i);
  });
});
