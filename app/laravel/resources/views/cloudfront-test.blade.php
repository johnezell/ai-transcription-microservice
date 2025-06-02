<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudFront URL Signing Test</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        button {
            background: #007bff;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        button:hover {
            background: #0056b3;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 12px;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .section {
            margin-bottom: 40px;
            padding: 20px;
            border: 1px solid #e9ecef;
            border-radius: 4px;
        }
        .section h2 {
            margin-top: 0;
            color: #495057;
        }
        .url-result {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            word-break: break-all;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 CloudFront URL Signing Test Interface</h1>
        
        <!-- Configuration Validation Section -->
        <div class="section">
            <h2>1. Configuration Validation</h2>
            <p>First, let's check if your CloudFront configuration is valid:</p>
            <button onclick="validateConfig()">Validate Configuration</button>
            <div id="config-result"></div>
        </div>

        <!-- Single URL Signing Section -->
        <div class="section">
            <h2>2. Sign Single URL</h2>
            <div class="form-group">
                <label for="server">CloudFront Domain:</label>
                <input type="text" id="server" placeholder="https://d1234567890.cloudfront.net" value="https://d1234567890.cloudfront.net">
            </div>
            <div class="form-group">
                <label for="file">File Path:</label>
                <input type="text" id="file" placeholder="/path/to/your/file.mp4" value="/uploads/videos/sample-video.mp4">
            </div>
            <div class="form-group">
                <label for="seconds">Expiration (seconds):</label>
                <input type="number" id="seconds" value="3600" min="1" max="86400">
            </div>
            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="whitelist">
                    <label for="whitelist">Enable IP Whitelisting</label>
                </div>
            </div>
            <button onclick="signSingleUrl()">Sign URL</button>
            <div id="single-url-result"></div>
        </div>

        <!-- Multiple URLs Signing Section -->
        <div class="section">
            <h2>3. Sign Multiple URLs</h2>
            <div class="form-group">
                <label for="urls">URLs (one per line):</label>
                <textarea id="urls" rows="5" placeholder="https://d1234567890.cloudfront.net/video1.mp4
https://d1234567890.cloudfront.net/video2.mp4
https://d1234567890.cloudfront.net/audio/track.mp3">https://d1234567890.cloudfront.net/video1.mp4
https://d1234567890.cloudfront.net/video2.mp4
https://d1234567890.cloudfront.net/audio/track.mp3</textarea>
            </div>
            <div class="form-group">
                <label for="multi-seconds">Expiration (seconds):</label>
                <input type="number" id="multi-seconds" value="1800" min="1" max="86400">
            </div>
            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" id="multi-whitelist">
                    <label for="multi-whitelist">Enable IP Whitelisting</label>
                </div>
            </div>
            <button onclick="signMultipleUrls()">Sign Multiple URLs</button>
            <div id="multiple-urls-result"></div>
        </div>

        <!-- Quick Test with S3 File -->
        <div class="section">
            <h2>4. Quick Test with Your S3 File</h2>
            <p>Enter your actual S3 file details here:</p>
            <div class="form-group">
                <label for="s3-domain">Your CloudFront Domain:</label>
                <input type="text" id="s3-domain" placeholder="https://your-actual-domain.cloudfront.net">
            </div>
            <div class="form-group">
                <label for="s3-file">Your S3 Object Key:</label>
                <input type="text" id="s3-file" placeholder="/your/actual/file.mp4">
            </div>
            <button onclick="testS3File()">Test Your S3 File</button>
            <div id="s3-test-result"></div>
        </div>

        <!-- Instructions -->
        <div class="section">
            <h2>📋 Instructions</h2>
            <div class="info result">
<strong>To test your CloudFront signing service:</strong>

1. <strong>Configuration:</strong> Make sure you have:
   - CloudFront private key file in storage/app/cloudfront/
   - Correct key pair ID in your .env file
   - CloudFront distribution configured with trusted key groups

2. <strong>S3 File Testing:</strong>
   - Replace the example domains with your actual CloudFront domain
   - Use your actual S3 object keys (file paths)
   - Test with different file types (video, audio, PDF, etc.)

3. <strong>Verification:</strong>
   - Copy the signed URL and test it in a browser
   - Check that the URL works and serves your content
   - Verify expiration by waiting for the URL to expire

4. <strong>API Testing:</strong>
   - Use the generated URLs in your application
   - Test the API endpoints with curl or Postman
   - Monitor Laravel logs for any errors
            </div>
        </div>
    </div>

    <script>
        // Set up CSRF token for all AJAX requests
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        async function makeRequest(url, method = 'GET', data = null) {
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            };

            if (data) {
                options.body = JSON.stringify(data);
            }

            try {
                const response = await fetch(url, options);
                return await response.json();
            } catch (error) {
                return { success: false, error: error.message };
            }
        }

        function displayResult(elementId, result, isSuccess = null) {
            const element = document.getElementById(elementId);
            const success = isSuccess !== null ? isSuccess : result.success;
            
            element.innerHTML = `<div class="result ${success ? 'success' : 'error'}">
                ${JSON.stringify(result, null, 2)}
            </div>`;
        }

        async function validateConfig() {
            const result = await makeRequest('/api/cloudfront/validate-config');
            displayResult('config-result', result);
        }

        async function signSingleUrl() {
            const data = {
                server: document.getElementById('server').value,
                file: document.getElementById('file').value,
                seconds: parseInt(document.getElementById('seconds').value),
                whitelist: document.getElementById('whitelist').checked
            };

            const result = await makeRequest('/api/cloudfront/sign-url', 'POST', data);
            displayResult('single-url-result', result);
            
            if (result.success) {
                const urlDiv = document.createElement('div');
                urlDiv.className = 'url-result';
                urlDiv.innerHTML = `<strong>Signed URL:</strong><br><a href="${result.signed_url}" target="_blank">${result.signed_url}</a>`;
                document.getElementById('single-url-result').appendChild(urlDiv);
            }
        }

        async function signMultipleUrls() {
            const urlsText = document.getElementById('urls').value;
            const urls = urlsText.split('\n').filter(url => url.trim());
            
            const data = {
                urls: urls,
                seconds: parseInt(document.getElementById('multi-seconds').value),
                whitelist: document.getElementById('multi-whitelist').checked
            };

            const result = await makeRequest('/api/cloudfront/sign-multiple-urls', 'POST', data);
            displayResult('multiple-urls-result', result);
            
            if (result.success && result.signed_urls) {
                const urlsDiv = document.createElement('div');
                urlsDiv.innerHTML = '<strong>Signed URLs:</strong>';
                
                Object.entries(result.signed_urls).forEach(([key, url]) => {
                    if (url) {
                        const urlDiv = document.createElement('div');
                        urlDiv.className = 'url-result';
                        urlDiv.innerHTML = `<strong>${key}:</strong><br><a href="${url}" target="_blank">${url}</a>`;
                        urlsDiv.appendChild(urlDiv);
                    }
                });
                
                document.getElementById('multiple-urls-result').appendChild(urlsDiv);
            }
        }

        async function testS3File() {
            const domain = document.getElementById('s3-domain').value;
            const file = document.getElementById('s3-file').value;
            
            if (!domain || !file) {
                displayResult('s3-test-result', { 
                    success: false, 
                    error: 'Please enter both CloudFront domain and S3 object key' 
                }, false);
                return;
            }

            const data = {
                server: domain,
                file: file,
                seconds: 3600,
                whitelist: false
            };

            const result = await makeRequest('/api/cloudfront/sign-url', 'POST', data);
            displayResult('s3-test-result', result);
            
            if (result.success) {
                const urlDiv = document.createElement('div');
                urlDiv.className = 'url-result';
                urlDiv.innerHTML = `
                    <strong>Your Signed S3 File URL:</strong><br>
                    <a href="${result.signed_url}" target="_blank">${result.signed_url}</a>
                    <br><br>
                    <strong>Test this URL:</strong><br>
                    1. Click the link above to test in browser<br>
                    2. Or copy and test with curl: <code>curl "${result.signed_url}"</code>
                `;
                document.getElementById('s3-test-result').appendChild(urlDiv);
            }
        }

        // Auto-validate configuration on page load
        window.addEventListener('load', validateConfig);
    </script>
</body>
</html>