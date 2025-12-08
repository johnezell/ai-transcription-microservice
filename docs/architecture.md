# Architecture

## Service Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        TrueFire S3 (tfstream)                       │
│                         Source Videos                               │
└───────────────────────────────┬─────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     Laravel API (Fargate)                           │
│              Job orchestration, Web UI, Queue dispatch              │
│                         Port 80 / 8080                              │
└───────────┬───────────────────┬───────────────────┬─────────────────┘
            │                   │                   │
            ▼                   ▼                   ▼
┌───────────────────┐ ┌─────────────────┐ ┌─────────────────────────┐
│ Audio Extraction  │ │  Transcription  │ │ Music Term Recognition  │
│    (Fargate)      │ │ (EC2 + GPU)     │ │      (Fargate)          │
│ Python + FFmpeg   │ │ Python + Whisper│ │   Python + spaCy        │
│    Port 5000      │ │   Port 5000     │ │     Port 5000           │
└─────────┬─────────┘ └────────┬────────┘ └───────────┬─────────────┘
          │                    │                      │
          └────────────────────┴──────────────────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │   EFS Shared Storage │
                    │  /jobs/{id}/...      │
                    └─────────────────────┘
```

## Services

| Service | Tech | Compute | Port | Purpose |
|---------|------|---------|------|---------|
| Laravel API | Laravel 12 / PHP 8.2 | ECS Fargate | 80 | Orchestration, Web UI, Job dispatch |
| Audio Extraction | Python/FFmpeg | ECS Fargate | 5000 | Video → WAV (16kHz mono) |
| Transcription | Python/Whisper | ECS EC2 (GPU) | 5000 | Speech-to-text |
| Music Term Recognition | Python/spaCy | ECS Fargate | 5000 | NLP terminology extraction |

## Data Flow

1. **Trigger**: CLI (`truefire:transcribe`) or API dispatches job to SQS
2. **Audio Extraction**: Downloads video from TrueFire S3, extracts WAV
3. **Transcription**: Whisper model on GPU generates transcript
4. **Term Recognition**: spaCy identifies music terminology
5. **Storage**: Results saved to EFS and database

## AWS Infrastructure

| Resource | Value |
|----------|-------|
| Account | 087439708020 (tfs-ai-services) |
| Region | us-east-1 |
| VPC CIDR | 10.25.0.0/16 |
| GPU Instance | g4dn.xlarge (staging) |
| Queue | SQS |
| Storage | EFS + RDS |

**Why separate account?** Isolated billing for GPU workloads, independent service quotas, security boundary.

## Job Directory Structure

Each transcription job creates:
```
/var/www/storage/app/public/s3/jobs/{job_id}/
├── video.mp4           # Downloaded source video
├── audio.wav           # Extracted audio (16kHz mono)
├── transcript.txt      # Plain text transcript
├── transcript.srt      # SRT subtitles
├── transcript.json     # Full Whisper output with timestamps
└── music_terms.json    # Extracted music terminology
```

## Inter-Service Communication

Services communicate via HTTP callbacks to Laravel API:
- `POST /api/transcription/{id}/status` - Status updates from Python services
- Services share storage via EFS mounted at `/var/www/storage/app/public/s3`

