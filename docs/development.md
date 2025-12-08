# Development

## Key Files by Task

| Task | Files to Modify |
|------|-----------------|
| Add API endpoint | `app/laravel/routes/api.php`, `app/laravel/app/Http/Controllers/` |
| Modify transcription pipeline | `app/laravel/app/Jobs/`, `app/services/transcription/service.py` |
| Add music terms | `app/services/music-term-recognition/service.py` (FALLBACK_MUSIC_TERMS) or Laravel admin UI |
| Change audio extraction | `app/services/audio-extraction/service.py` |
| Update database schema | `app/laravel/database/migrations/` |
| Modify TrueFire integration | `app/laravel/app/Models/TrueFire/`, `app/laravel/app/Console/Commands/TranscribeTrueFire*.php` |

## Adding Music Terms

**Option 1: Laravel Admin UI** (preferred)
- Navigate to `/admin/terminology`
- Add terms via web interface
- Service fetches from API on startup

**Option 2: Code fallback** (when API unavailable)
Edit `app/services/music-term-recognition/service.py`:
```python
FALLBACK_MUSIC_TERMS = {
    "guitar_techniques": ["palm muting", "hammer-on", ...],
    "your_new_category": ["term1", "term2", ...]
}
```

## Processing Pipeline Extension

The pipeline is: Audio → Transcription → Term Recognition

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
| `MusicTerm` | music_terms | Recognized term definitions |
| `Course` | courses | Optional course grouping |

**TrueFire models** (read-only, external DB):
| Model | Connection | Purpose |
|-------|------------|---------|
| `TrueFireCourse` | truefire | Course metadata |
| `TrueFireSegment` | truefire | Video segment info, S3 paths |
| `TrueFireChannel` | truefire | Channel grouping |

## TrueFire Integration

Videos come from TrueFire's S3 bucket `tfstream`. The segment model handles path conversion:

```
Database: "mp4:guitar-course/segment-01"
    ↓
S3 Key:   "guitar-course/segment-01_hi.mp4"
```

Quality options: `_low`, `_med`, `_hi` (default: `_hi`)

**CLI usage:**
```bash
# Dry run (show what would happen)
php artisan truefire:transcribe 12345 --dry-run

# Queue for ECS processing
php artisan truefire:transcribe 12345 --dispatch

# Local sync processing (requires local services)
php artisan truefire:transcribe 12345 --sync
```

## Environment Variables

Key variables in `.env` / docker-compose:
```
AUDIO_SERVICE_URL=http://audio-extraction-service:5000
TRANSCRIPTION_SERVICE_URL=http://transcription-service:5000
MUSIC_TERM_SERVICE_URL=http://music-term-recognition-service:5000
QUEUE_CONNECTION=database  # or 'sqs' for ECS
```

