# Development

## Key Files by Task

| Task | Files to Modify |
|------|-----------------|
| Add API endpoint | `app/laravel/routes/api.php`, `app/laravel/app/Http/Controllers/` |
| Modify transcription pipeline | `app/laravel/app/Jobs/`, `app/services/transcription/service.py` |
| Add terminology terms | Admin UI at `/admin/terminology` or `FALLBACK_MUSIC_TERMS` in service |
| Change audio extraction | `app/services/audio-extraction/service.py` |
| Update database schema | `app/laravel/database/migrations/` |
| Add new data source | Create models + CLI command (see TrueFire example) |

## Terminology System

The terminology recognition service extracts domain-specific terms from transcripts. Terms are organized by category.

**Managing terms via Admin UI** (preferred):
- Navigate to `/admin/terminology`
- Add categories and terms via web interface
- Service fetches from API on startup and refresh

**Fallback terms** (when API unavailable):
Edit `app/services/music-term-recognition/service.py`:
```python
FALLBACK_MUSIC_TERMS = {
    "category_name": ["term1", "term2", ...],
    "another_category": ["term3", "term4", ...]
}
```

**Adding a new terminology domain**:
1. Create category in admin UI
2. Add terms to that category
3. Optionally add fallback terms in service code

## Processing Pipeline Extension

Pipeline: Audio Extraction → Transcription → Terminology Recognition

**To add a new processing step:**
1. Create new Python service in `app/services/your-service/`
2. Add Dockerfile: `Dockerfile.your-service`
3. Add to `docker-compose.yml` and `docker-compose.local.yml`
4. Create Laravel job in `app/laravel/app/Jobs/`
5. Chain job dispatch in existing pipeline

## Database Models

| Model | Table | Purpose |
|-------|-------|---------|
| `Video` | videos | Main transcription records |
| `TranscriptionLog` | transcription_logs | Timing and progress tracking |
| `Term` | terms | Recognized term definitions |
| `TermCategory` | term_categories | Term groupings |
| `Course` | courses | Optional content grouping |

## Data Source Integration: TrueFire

TrueFire is one data source integration. Use it as a pattern for adding others.

**Models** (read-only, external PostgreSQL):
| Model | Connection | Purpose |
|-------|------------|---------|
| `TrueFireCourse` | truefire | Course metadata |
| `TrueFireSegment` | truefire | Video segment info, S3 paths |
| `TrueFireChannel` | truefire | Channel grouping |

**S3 path conversion**:
```
Database: "mp4:course-name/segment-01"
    ↓
S3 Key:   "course-name/segment-01_hi.mp4"
```

Quality options: `_low`, `_med`, `_hi` (default: `_hi`)

**CLI usage:**
```bash
php artisan truefire:transcribe 12345 --dry-run    # Preview
php artisan truefire:transcribe 12345 --dispatch   # Queue for ECS
php artisan truefire:transcribe 12345 --sync       # Run locally
```

## Environment Variables

Key variables in `.env` / docker-compose:
```
AUDIO_SERVICE_URL=http://audio-extraction-service:5000
TRANSCRIPTION_SERVICE_URL=http://transcription-service:5000
TERMINOLOGY_SERVICE_URL=http://terminology-service:5000
QUEUE_CONNECTION=sqs  # or 'database' for local testing
```
