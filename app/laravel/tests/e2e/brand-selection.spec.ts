import { test, expect } from '@playwright/test';

test.describe('Brand Selection Page', () => {
  test('displays all four brands', async ({ page }) => {
    await page.goto('/articles/select-brand');
    
    // Check page title
    await expect(page).toHaveTitle(/Select Brand/);
    
    // Check welcome header
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Welcome');
    
    // Check all four brands are displayed - use getByRole for h3 headings in cards
    await expect(page.getByRole('heading', { name: 'TrueFire' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'ArtistWorks' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Blayze' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'FaderPro' })).toBeVisible();
  });

  test('displays brand descriptions', async ({ page }) => {
    await page.goto('/articles/select-brand');
    
    await expect(page.getByText('Guitar Lessons from the Pros')).toBeVisible();
    await expect(page.getByText('Online Music Lessons with Video Exchange')).toBeVisible();
    await expect(page.getByText('Private 1:1 Coaching Platform')).toBeVisible();
    await expect(page.getByText('Music Production Courses')).toBeVisible();
  });

  test('clicking TrueFire navigates to articles index', async ({ page }) => {
    await page.goto('/articles/select-brand');
    
    // Click on TrueFire card - use the heading inside the link
    await page.getByRole('heading', { name: 'TrueFire' }).click();
    
    // Should navigate to articles index with brandId
    await expect(page).toHaveURL(/\/articles\?brandId=truefire/);
    
    // Header should show TrueFire
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/TrueFire/);
  });

  test('clicking ArtistWorks navigates to articles index', async ({ page }) => {
    await page.goto('/articles/select-brand');
    
    await page.getByRole('link', { name: /ArtistWorks/i }).first().click();
    
    await expect(page).toHaveURL(/\/articles\?brandId=artistworks/);
  });

  test('clicking Blayze navigates to articles index', async ({ page }) => {
    await page.goto('/articles/select-brand');
    
    await page.getByRole('link', { name: /Blayze/i }).first().click();
    
    await expect(page).toHaveURL(/\/articles\?brandId=blayze/);
  });

  test('clicking FaderPro navigates to articles index', async ({ page }) => {
    await page.goto('/articles/select-brand');
    
    await page.getByRole('link', { name: /FaderPro/i }).first().click();
    
    await expect(page).toHaveURL(/\/articles\?brandId=faderpro/);
  });

  test('has back to dashboard link', async ({ page }) => {
    await page.goto('/articles/select-brand');
    
    const backLink = page.getByRole('link', { name: /Back to Dashboard/i });
    await expect(backLink).toBeVisible();
    await expect(backLink).toHaveAttribute('href', /dashboard/);
  });
});
