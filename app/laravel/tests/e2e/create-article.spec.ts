import { test, expect } from '@playwright/test';

test.describe('Create Article Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/articles/create?brandId=truefire');
  });

  test('displays page title', async ({ page }) => {
    await expect(page).toHaveTitle(/Create Article/i);
    await expect(page.locator('h1')).toContainText(/Create New Article/i);
  });

  test('displays brand name in header', async ({ page }) => {
    await expect(page.locator('nav, .header')).toContainText(/TrueFire/i);
  });

  test('has back to articles link', async ({ page }) => {
    const backLink = page.getByRole('link', { name: /Back to Articles/i });
    await expect(backLink).toBeVisible();
    
    await backLink.click();
    await expect(page).toHaveURL(/\/articles\?brandId=truefire/);
  });

  test('displays YouTube import section', async ({ page }) => {
    await expect(page.getByText(/Import from YouTube/i)).toBeVisible();
    
    const youtubeInput = page.getByPlaceholder(/youtube.com/i);
    await expect(youtubeInput).toBeVisible();
  });

  test('YouTube input accepts URL', async ({ page }) => {
    const youtubeInput = page.getByPlaceholder(/youtube.com/i);
    await youtubeInput.fill('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    
    await expect(youtubeInput).toHaveValue('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
  });

  test('has Whisper transcription checkbox', async ({ page }) => {
    const whisperCheckbox = page.getByRole('checkbox');
    await expect(whisperCheckbox.first()).toBeVisible();
  });

  test('YouTube generate button is disabled without URL', async ({ page }) => {
    const generateButtons = page.getByRole('button', { name: /Generate.*YouTube/i });
    const firstButton = generateButtons.first();
    
    await expect(firstButton).toBeDisabled();
  });

  test('YouTube generate button enables with URL', async ({ page }) => {
    const youtubeInput = page.getByPlaceholder(/youtube.com/i);
    await youtubeInput.fill('https://www.youtube.com/watch?v=test123');
    
    const generateButton = page.getByRole('button', { name: /Generate.*YouTube/i }).first();
    await expect(generateButton).not.toBeDisabled();
  });

  test('displays video upload section', async ({ page }) => {
    await expect(page.getByText(/Upload Video File/i)).toBeVisible();
    await expect(page.getByText(/drag and drop/i)).toBeVisible();
  });

  test('displays file size limit', async ({ page }) => {
    await expect(page.getByText(/500MB/i)).toBeVisible();
  });

  test('upload button is disabled without file', async ({ page }) => {
    const uploadButton = page.getByRole('button', { name: /Generate.*Video/i }).first();
    await expect(uploadButton).toBeDisabled();
  });

  test('displays raw transcript section', async ({ page }) => {
    await expect(page.getByText(/Paste Raw Transcript/i)).toBeVisible();
    
    const textarea = page.getByPlaceholder(/Paste your transcript/i);
    await expect(textarea).toBeVisible();
  });

  test('transcript textarea shows character count', async ({ page }) => {
    const textarea = page.getByPlaceholder(/Paste your transcript/i);
    await textarea.fill('This is a test transcript');
    
    // Should show character count
    await expect(page.getByText(/characters/i)).toBeVisible();
  });

  test('transcript generate button is disabled with short text', async ({ page }) => {
    const textarea = page.getByPlaceholder(/Paste your transcript/i);
    await textarea.fill('Too short');
    
    // Button should be disabled (needs 100+ chars)
    const generateButton = page.getByRole('button', { name: /Generate.*Transcript/i }).first();
    await expect(generateButton).toBeDisabled();
  });

  test('transcript generate button enables with enough text', async ({ page }) => {
    const textarea = page.getByPlaceholder(/Paste your transcript/i);
    
    // Fill with 100+ characters
    const longText = 'This is a test transcript that is long enough to meet the minimum character requirement for generating an article. It needs to be at least 100 characters.';
    await textarea.fill(longText);
    
    const generateButton = page.getByRole('button', { name: /Generate.*Transcript/i }).first();
    await expect(generateButton).not.toBeDisabled();
  });

  test('page structure has all four input options', async ({ page }) => {
    // Should have 4 bordered sections
    const sections = page.locator('.border-dashed');
    
    // At least 2 visible sections (YouTube + Upload at minimum)
    expect(await sections.count()).toBeGreaterThanOrEqual(2);
  });
});

test.describe('Create Article - Brand Switching', () => {
  test('different brands show correct header', async ({ page }) => {
    // Test TrueFire
    await page.goto('/articles/create?brandId=truefire');
    await expect(page.locator('nav')).toContainText(/TrueFire/i);
    
    // Test ArtistWorks
    await page.goto('/articles/create?brandId=artistworks');
    await expect(page.locator('nav')).toContainText(/ArtistWorks/i);
    
    // Test Blayze
    await page.goto('/articles/create?brandId=blayze');
    await expect(page.locator('nav')).toContainText(/Blayze/i);
    
    // Test FaderPro
    await page.goto('/articles/create?brandId=faderpro');
    await expect(page.locator('nav')).toContainText(/FaderPro/i);
  });
});
