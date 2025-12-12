# CLAUDE.md - Thoth AI Reference Index

## Identity
- **Name**: Thoth (AI Transcription & Article Generation Microservice)
- **Purpose**: Video/audio transcription with terminology extraction AND AI-powered article generation
- **Use Cases**: AI training data, subtitling, searchable content, accessibility, blog article creation
- **Status**: Internal tool, staging environment
- **Domain**: thoth-staging.tfs.services
- **AWS Account**: 087439708020 (tfs-ai-services)

## Quick Start
```bash
make local          # Start backend only (http://localhost:8080)
make local-dev      # Start with Vite hot-reload for UI development
make local-logs     # View logs
make local-stop     # Stop containers
```

## Local UI Development
```bash
make local-dev      # Start Laravel + Vite hot-reload
                    # Laravel: http://localhost:8080
                    # Vite:    http://localhost:3005
make local-logs-vite # View Vite logs
make local-build    # Build production assets
make local-shell    # Open shell in container
make local-npm CMD="install axios"  # Run npm commands
```

## Testing
```bash
make test           # Run all tests
make test-unit      # Run unit tests only
make test-feature   # Run feature tests only
make test-articles  # Run article-related tests
make test-filter FILTER=test_can_list_articles  # Run specific test
make test-coverage  # Run tests with coverage report
```

## Task-Based Routing

| If you need to... | Read this doc |
|-------------------|---------------|
| Understand the system design, services, data flow | [docs/architecture.md](docs/architecture.md) |
| Deploy, monitor, or troubleshoot | [docs/operations.md](docs/operations.md) |
| Modify code, extend functionality, add terms | [docs/development.md](docs/development.md) |
| Work with API endpoints or service communication | [docs/api-reference.md](docs/api-reference.md) |
| Set up or access the bastion host | [docs/bastion-setup.md](docs/bastion-setup.md) |

## Critical Reminders

**ALWAYS**:
- Use `make` commands for terraform (runs via Docker for version consistency)
- Use AWS profiles (`tfs-ai-staging`), never hardcode credentials
- Test locally with `make local` before deploying

**NEVER**:
- Run terraform commands directly outside of Make/Docker
- Commit `.env` files or credentials
- Assume GPU instances are immediately available (5-10 min provision time)

## Key Paths
| Path | Purpose |
|------|---------|
| `app/laravel/` | Laravel API and web UI |
| `app/services/` | Python microservices (audio, transcription, terminology) |
| `terraform/` | Infrastructure as code |
| `docker-compose.local.yml` | Local development setup |

## Data Source Integrations

Currently integrated:
- **TrueFire**: Guitar instruction videos from S3 bucket `tfstream`
  - CLI: `php artisan truefire:transcribe {segment_id} --dispatch`
  - Models: `app/laravel/app/Models/TrueFire/`

Future integrations could include direct upload, YouTube, Vimeo, etc.

## Article Generation (NEW)

Transform video transcripts into professional blog articles using AWS Bedrock (Claude).

### Quick Usage
1. Navigate to `/articles` in the web UI
2. Click "Create Article"
3. Choose one of:
   - **YouTube URL** - Transcribes with Whisper (industry-optimized) then generates article
   - **Existing Thoth video** - Use an already-transcribed video
   - **Raw transcript** - Paste transcript text directly
4. Article generates in background using Bedrock Claude

### YouTube Integration
Two modes for YouTube videos:
- **Whisper mode** (default): Downloads audio → Whisper transcription with industry prompts → generates article
- **Captions mode**: Uses YouTube's existing captions (faster but lower quality)

### Multi-Brand Support
Four brands with customized prompts:
- **TrueFire** - Guitar education
- **ArtistWorks** - Music lessons
- **Blayze** - 1:1 coaching
- **FaderPro** - Music production

### API Endpoints
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/articles` | GET | List articles (with `?brandId=` filter) |
| `/api/articles/{id}` | GET | Get single article |
| `/api/articles/from-video` | POST | Generate from Thoth video |
| `/api/articles/from-transcript` | POST | Generate from raw text |
| `/api/articles/{id}` | PUT | Update article |
| `/api/articles/{id}` | DELETE | Soft delete article |
| `/api/article-settings` | GET/PUT | Brand prompt settings |

### Key Files
| Path | Purpose |
|------|---------|
| `app/Services/ArticleGeneratorService.php` | Bedrock integration |
| `app/Jobs/GenerateArticleJob.php` | Async article generation |
| `app/Models/Article.php` | Article model |
| `app/Models/BrandSetting.php` | Per-brand settings |
| `terraform/42-iam-bedrock.tf` | Bedrock IAM permissions |

### Database Tables
- `articles` - Generated articles with SEO metadata
- `article_comments` - Threaded commenting system
- `brand_settings` - LLM model and prompt per brand
