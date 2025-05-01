<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Microservices App</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />

        <!-- Styles -->
        <style>
            /* Basic styles */
            html, body {
                height: 100%;
                margin: 0;
                padding: 0;
                font-family: 'Figtree', sans-serif;
                background-color: #f8fafc;
                color: #1a202c;
            }
            .container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 2rem;
                display: flex;
                flex-direction: column;
                min-height: 100vh;
            }
            header {
                margin-bottom: 2rem;
            }
            .logo {
                font-size: 2.5rem;
                font-weight: 600;
                color: #3b82f6;
            }
            main {
                flex: 1;
            }
            .card {
                background-color: white;
                border-radius: 0.5rem;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                padding: 2rem;
                margin-bottom: 2rem;
            }
            h1 {
                font-size: 2rem;
                margin-bottom: 1.5rem;
                color: #1a202c;
            }
            p {
                margin-bottom: 1rem;
                line-height: 1.6;
            }
            .btn {
                display: inline-block;
                background-color: #3b82f6;
                color: white;
                padding: 0.75rem 1.5rem;
                border-radius: 0.375rem;
                font-weight: 600;
                text-decoration: none;
                transition: background-color 0.3s;
            }
            .btn:hover {
                background-color: #2563eb;
            }
            .features {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 2rem;
                margin-top: 2rem;
            }
            .feature {
                background-color: white;
                border-radius: 0.5rem;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                padding: 1.5rem;
            }
            .feature h2 {
                color: #3b82f6;
                font-size: 1.25rem;
                margin-bottom: 0.75rem;
            }
            footer {
                margin-top: 3rem;
                text-align: center;
                padding: 1.5rem 0;
                border-top: 1px solid #e2e8f0;
                color: #64748b;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <header>
                <div class="logo">Placeholder App</div>
            </header>

            <main>
                <div class="card">
                    <h1>Welcome to Placeholder Application</h1>
                    <p>
                        This is a placeholder application built with Laravel. Use this as a starting point for your project.
                    </p>
                    
                    <p>
                        <a href="/api/hello" class="btn">Try the Hello API</a>
                    </p>
                </div>

                <div class="features">
                    <div class="feature">
                        <h2>Laravel Backend</h2>
                        <p>Built with Laravel, a powerful PHP framework with expressive, elegant syntax.</p>
                    </div>
                    
                    <div class="feature">
                        <h2>RESTful API</h2>
                        <p>Ready-to-use API endpoints to build your application services.</p>
                    </div>
                    
                    <div class="feature">
                        <h2>Microservices Ready</h2>
                        <p>Designed to work with other microservices in a containerized environment.</p>
                    </div>
                </div>
            </main>

            <footer>
                <p>Laravel Placeholder &copy; {{ date('Y') }}</p>
            </footer>
        </div>
    </body>
</html>
