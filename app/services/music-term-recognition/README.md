# Music Term Recognition Service

## Status: DISABLED ⚠️

This service is currently **disabled** but preserved for future course analytics and insights.

## Purpose

The Music Term Recognition Service provides structured analysis of guitar lesson transcripts:

### 🎯 **Core Capabilities**
- **Categorized Term Detection**: Groups music terms by type (techniques, theory, equipment, etc.)
- **Analytics & Metrics**: Term density, frequency analysis, course complexity scoring
- **Structured Data**: JSON output suitable for dashboards and reporting
- **Dynamic Vocabulary**: Fetches updated term lists from Laravel API

### 📊 **Use Cases for Future Implementation**
1. **Course Analytics Dashboard**
   - Technical density per segment/course
   - Topic distribution analysis
   - Difficulty assessment based on terminology

2. **Content Discovery & Search**
   - Filter courses by technique type
   - Search by music theory concepts covered
   - Equipment recommendation analysis

3. **Educational Insights**
   - Identify most technique-heavy segments
   - Track theory concept progression
   - Course tagging and categorization

## Current Architecture

### 🔧 **Technology Stack**
- **Framework**: Flask/Python
- **NLP**: spaCy with PhraseMatcher
- **Input**: Plain transcript text files
- **Output**: Categorized JSON files with term analysis

### 📂 **Sample Output Structure**
```json
{
  "total_terms": 23,
  "terms_by_category": {
    "guitar_techniques": ["palm muting", "hammer-on", "pull-off"],
    "guitar_parts": ["bridge", "fretboard", "pickup"],
    "music_theory": ["chord", "scale", "pentatonic", "major"],
    "music_equipment": ["amplifier", "distortion", "overdrive"]
  },
  "term_instances": [
    {
      "term": "hammer-on",
      "category": "guitar_techniques",
      "position": {"start": 15, "end": 16},
      "context": "Try using a hammer-on technique to connect"
    }
  ]
}
```

## Why It's Currently Disabled

1. **Primary Need Met**: The `guitar_term_evaluator` (with Ollama AI) handles the main requirement of improving transcription accuracy
2. **Pipeline Focus**: Currently prioritizing transcription completion over analytics
3. **Resource Efficiency**: No point running unused containers
4. **Clean Architecture**: Avoiding service overlap until analytics are needed

## How to Re-Enable When Ready

### 1. **Update Docker Compose**
```yaml
# In docker-compose.yml, uncomment the music-term-recognition-service section
music-term-recognition-service:
  build:
    context: .
    dockerfile: Dockerfile.music-service
  container_name: music-service
  restart: unless-stopped
  volumes:
    - ./app/services/music-term-recognition:/app
    - ./app/shared:/var/www/storage/app/public/s3:delegated
  depends_on:
    - laravel
    - transcription-service
  networks:
    - app-network
  environment:
    - LARAVEL_API_URL=http://laravel/api
  ports:
    - "5052:5000"
```

### 2. **Re-enable Environment Variable**
```yaml
# In laravel service environment section
- MUSIC_TERM_SERVICE_URL=http://music-term-recognition-service:5000
```

### 3. **Update Laravel Jobs**
```php
// In relevant controllers/jobs, change from:
return response()->json([
    'success' => false, 
    'message' => 'Terminology recognition is currently disabled'
], 400);

// To: Uncomment the actual processing logic
```

### 4. **Start the Services**
```bash
docker-compose up -d music-term-recognition-service
```

## Integration Points

The service integrates with:
- **TruefireSegmentTerminologyJob** - Laravel job for processing
- **TerminologyController** - API endpoints for manual triggers
- **TranscriptionController** - Callback handling for completed analysis

## Comparison with guitar_term_evaluator

| Feature | music-term-recognition | guitar_term_evaluator |
|---------|----------------------|----------------------|
| **Purpose** | Analytics & categorization | Confidence enhancement |
| **Method** | spaCy pattern matching | AI-powered evaluation |
| **Output** | Separate analysis JSON | Enhanced transcription |
| **When** | Post-processing analysis | During transcription |
| **Use Case** | Course insights | Real-time accuracy |

## Future Roadmap

When ready to implement analytics:

1. **Phase 1**: Re-enable service and test with completed transcriptions
2. **Phase 2**: Build analytics dashboard consuming the structured data
3. **Phase 3**: Implement course insights and search features
4. **Phase 4**: Consider merging best features with guitar_term_evaluator

---

**📝 Note**: This service complements rather than competes with the `guitar_term_evaluator`. While the guitar term evaluator enhances transcription accuracy in real-time, this service provides structured analysis for course-level insights and analytics. 