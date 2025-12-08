# CLAUDE.md - Thoth AI Reference Index

## Identity
- **Name**: Thoth (AI Transcription Microservice)
- **Purpose**: Generate transcripts from TrueFire guitar instruction videos for AI training data; future multi-language subtitling
- **Status**: Internal tool, staging environment only
- **Domain**: thoth-staging.tfs.services
- **AWS Account**: 087439708020 (tfs-ai-services)

## Quick Start
```bash
make local          # Start local dev (http://localhost:8080)
make local-logs     # View logs
make local-stop     # Stop containers
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
| `app/services/` | Python microservices (audio, transcription, music-terms) |
| `terraform/` | Infrastructure as code |
| `docker-compose.local.yml` | Local development setup |

## TrueFire Integration
- Source videos: S3 bucket `tfstream` (TrueFire's account)
- Database: PostgreSQL connection `truefire` for segment metadata
- CLI: `php artisan truefire:transcribe {segment_id} --dispatch`
