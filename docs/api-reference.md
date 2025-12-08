# API Reference

## Laravel API Endpoints

### Health & Connectivity
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/health` | Health check for load balancer |
| GET | `/api/hello` | Simple connectivity test |
| GET | `/api/connectivity-test` | Test connections to Python services |

### Transcription
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/transcription` | Dispatch new transcription job |
| GET | `/api/transcription/{jobId}` | Get job status |
| POST | `/api/transcription/{jobId}/status` | Update job status (callback from services) |

### Videos
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/videos/{id}` | Get video details |
| GET | `/api/videos/{id}/status` | Poll processing status |
| GET | `/api/videos/{id}/transcript-json` | Get full transcript JSON |
| GET | `/api/videos/{id}/terminology-json` | Get extracted terms JSON |
| POST | `/api/videos/{id}/terminology` | Trigger terminology recognition |

*Legacy endpoints (`/api/videos/{id}/music-terms`) redirect to terminology.*

### Course/Content Analysis
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/courses/{course}/terminology` | All terms across course |
| GET | `/api/courses/{course}/terminology-frequency` | Term frequency analysis |
| GET | `/api/courses/{course}/transcripts` | Combined transcripts |
| POST | `/api/courses/{course}/search` | Search across transcripts |

## Python Service Endpoints

All Python services expose the same pattern:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/health` | Health check |
| GET | `/connectivity-test` | Test Laravel API connection |
| POST | `/process` | Process a job |

**Terminology Service only:**
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/refresh-terms` | Reload terms from Laravel API |

## Job States

```
pending → processing → extracting_audio → transcribing → recognizing_terms → completed
                ↓              ↓                ↓              ↓
              failed        failed           failed        failed
```

## Service Communication Flow

```
1. Laravel dispatches job to SQS
2. Laravel calls Audio Service:    POST /process {job_id, source_bucket, source_key}
3. Audio Service calls back:       POST /api/transcription/{id}/status
4. Laravel calls Transcription:    POST /process {job_id}
5. Transcription calls back:       POST /api/transcription/{id}/status
6. Laravel calls Terminology:      POST /process {job_id}
7. Terminology calls back:         POST /api/transcription/{id}/status
```

## Status Update Payload

Python services send status updates:
```json
{
  "status": "completed|failed|processing",
  "response_data": {
    "message": "Description",
    "transcript_text": "...",
    "confidence_score": 0.95,
    "metadata": {}
  },
  "error_message": "Only if failed",
  "completed_at": "2025-01-01T00:00:00Z"
}
```
