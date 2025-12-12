import { test, expect } from '@playwright/test';

test.describe('Settings Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/articles/settings?brandId=truefire');
  });

  test('displays page title', async ({ page }) => {
    await expect(page).toHaveTitle(/Settings/i);
  });

  test('displays brand name in header', async ({ page }) => {
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/TrueFire/i);
  });

  test('has back to articles link', async ({ page }) => {
    const backLink = page.getByRole('link', { name: /Back to Articles/i }).first();
    await expect(backLink).toBeVisible();
  });

  test('displays LLM Model section', async ({ page }) => {
    await expect(page.getByText(/LLM Model/i)).toBeVisible();
    
    // Should have model dropdown
    const modelSelect = page.locator('select').first();
    await expect(modelSelect).toBeVisible();
  });

  test('LLM model dropdown has options', async ({ page }) => {
    const modelSelect = page.locator('select').first();
    
    // Check dropdown exists and has options
    await expect(modelSelect).toBeVisible();
    const optionCount = await modelSelect.locator('option').count();
    expect(optionCount).toBeGreaterThanOrEqual(2);
  });

  test('displays Brand-Specific Prompts section', async ({ page }) => {
    await expect(page.getByText(/Brand-Specific Prompts/i)).toBeVisible();
  });

  test('has brand tabs', async ({ page }) => {
    // All brands should be in tabs
    await expect(page.getByRole('button', { name: /TrueFire/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /ArtistWorks/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /Blayze/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /FaderPro/i })).toBeVisible();
  });

  test('displays current brand info', async ({ page }) => {
    await expect(page.getByText('Guitar Lessons from the Pros')).toBeVisible();
    await expect(page.getByText('truefire.com')).toBeVisible();
  });

  test('has system prompt textarea', async ({ page }) => {
    const promptTextarea = page.locator('textarea');
    await expect(promptTextarea).toBeVisible();
    
    // Should have some default content
    const value = await promptTextarea.inputValue();
    expect(value.length).toBeGreaterThan(50);
  });

  test('has restore default button', async ({ page }) => {
    const restoreButton = page.getByRole('button', { name: /Restore default/i });
    await expect(restoreButton).toBeVisible();
  });

  test('has save settings button', async ({ page }) => {
    const saveButton = page.getByRole('button', { name: /Save Settings/i });
    await expect(saveButton).toBeVisible();
    await expect(saveButton).not.toBeDisabled();
  });

  test('has cancel button', async ({ page }) => {
    const cancelLink = page.getByRole('link', { name: /Cancel/i });
    await expect(cancelLink).toBeVisible();
  });

  test('displays tips section', async ({ page }) => {
    await expect(page.getByText(/Tips for a good prompt/i)).toBeVisible();
    await expect(page.getByText(/target audience/i)).toBeVisible();
  });

  test('switching brand tab changes content', async ({ page }) => {
    // Click on ArtistWorks tab
    await page.getByRole('button', { name: /ArtistWorks/i }).click();
    
    // URL should update
    await expect(page).toHaveURL(/brandId=artistworks/);
    
    // Brand info should change
    await expect(page.getByText('Online Music Lessons with Video Exchange')).toBeVisible();
  });

  test('can edit system prompt', async ({ page }) => {
    const promptTextarea = page.locator('textarea');
    
    // Clear and type new content
    await promptTextarea.fill('This is a test prompt for testing purposes.');
    
    await expect(promptTextarea).toHaveValue('This is a test prompt for testing purposes.');
  });

  test('can change LLM model', async ({ page }) => {
    const modelSelect = page.locator('select').first();
    
    // Get current value
    const initialValue = await modelSelect.inputValue();
    
    // Get all options
    const options = await modelSelect.locator('option').allTextContents();
    
    if (options.length > 1) {
      // Select a different option
      await modelSelect.selectOption({ index: 1 });
      
      const newValue = await modelSelect.inputValue();
      expect(newValue).not.toBe('');
    }
  });
});

test.describe('Settings Page - Different Brands', () => {
  test('ArtistWorks settings page', async ({ page }) => {
    await page.goto('/articles/settings?brandId=artistworks');
    
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/ArtistWorks/i);
    await expect(page.getByText('artistworks.com')).toBeVisible();
  });

  test('Blayze settings page', async ({ page }) => {
    await page.goto('/articles/settings?brandId=blayze');
    
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/Blayze/i);
    await expect(page.getByText('blayze.io')).toBeVisible();
  });

  test('FaderPro settings page', async ({ page }) => {
    await page.goto('/articles/settings?brandId=faderpro');
    
    await expect(page.getByRole('heading', { level: 1 })).toContainText(/FaderPro/i);
    await expect(page.getByText('faderpro.com')).toBeVisible();
  });
});
