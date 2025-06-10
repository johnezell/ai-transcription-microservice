# Guitar Terminology Evaluator

## Overview

A Python service that post-processes transcription JSON files to improve confidence scores for guitar instruction terminology. Uses a local LLM to intelligently identify domain-specific guitar terms and artificially boost their confidence scores when they fall below a threshold.

## Problem Statement

Speech recognition systems like Whisper often assign low confidence scores to specialized terminology, even when the transcription is correct. For guitar instruction content, technical terms like "fret", "barre", "arpeggio", etc. may be transcribed correctly but marked with low confidence, leading to potential corrections or highlighting issues.

## Solution Architecture

```
Transcription JSON → Word Extraction → LLM Evaluation → Confidence Boosting → Updated JSON
```

### Key Components

1. **Flexible JSON Parser**: Adapts to different transcription JSON formats
2. **Local LLM Integration**: Uses local models (Ollama, etc.) for term evaluation
3. **Context-Aware Analysis**: Considers surrounding words for better accuracy
4. **Caching System**: Avoids redundant LLM calls for repeated terms
5. **Fallback Dictionary**: Known guitar terms for offline operation

## Features

- ✅ Domain-aware evaluation using LLM
- ✅ Context consideration for better accuracy
- ✅ Configurable confidence thresholds and boost amounts
- ✅ Caching to improve performance
- ✅ Fallback system for offline operation
- ✅ Preserves original JSON structure
- ✅ Metadata tracking of boosted terms
- ✅ CLI interface for easy usage

## Implementation

### Core Class Structure

```python
@dataclass
class WordSegment:
    word: str
    start: float
    end: float
    confidence: float
    original_confidence: float = None

class GuitarTerminologyEvaluator:
    def __init__(self, llm_endpoint, model_name, confidence_threshold, boost_amount)
    def extract_words_from_json(self, transcription_data) -> List[WordSegment]
    def query_local_llm(self, word, context) -> bool
    def evaluate_and_boost(self, transcription_json) -> Dict[str, Any]
```

### Complete Implementation

```python
import json
import requests
from typing import Dict, List, Any, Optional
from dataclasses import dataclass

@dataclass
class WordSegment:
    """Represents a word segment with timing and confidence"""
    word: str
    start: float
    end: float
    confidence: float
    original_confidence: float = None

class GuitarTerminologyEvaluator:
    """Service to evaluate and boost confidence for guitar instruction terms"""
    
    def __init__(self, 
                 llm_endpoint: str = "http://localhost:11434/api/generate",
                 model_name: str = "llama2",
                 confidence_threshold: float = 0.7,
                 boost_amount: float = 0.2):
        self.llm_endpoint = llm_endpoint
        self.model_name = model_name
        self.confidence_threshold = confidence_threshold
        self.boost_amount = boost_amount
        self.evaluation_cache = {}
        
        # Fallback guitar terms if LLM is unavailable
        self.known_guitar_terms = {
            "fret", "frets", "fretting", "fretboard", "chord", "chords", 
            "strumming", "picking", "barre", "capo", "tuning", "tablature", 
            "tab", "hammer", "pulloff", "slide", "bend", "vibrato",
            "fingerpicking", "flatpicking", "downstroke", "upstroke",
            "progression", "arpeggio", "scale", "pentatonic", "major", 
            "minor", "seventh", "diminished", "augmented", "guitar", 
            "acoustic", "electric", "bass", "amp", "amplifier", "distortion", 
            "overdrive", "tremolo", "whammy", "bridge", "saddle", "nut",
            "headstock", "tuners", "strings", "action", "intonation",
            "sustain", "mute", "palm", "alternate", "sweep", "tapping"
        }

    def extract_words_from_json(self, transcription_data: Dict[str, Any]) -> List[WordSegment]:
        """
        Extract word segments from transcription JSON.
        Adaptable to different JSON structures.
        """
        segments = []
        
        # Try different common JSON structures
        if 'segments' in transcription_data:
            # Whisper-style format
            for segment in transcription_data['segments']:
                if 'words' in segment:
                    for word_data in segment['words']:
                        segments.append(self._create_word_segment(word_data))
        
        elif 'words' in transcription_data:
            # Direct words array
            for word_data in transcription_data['words']:
                segments.append(self._create_word_segment(word_data))
        
        elif 'results' in transcription_data:
            # Some services use 'results'
            for result in transcription_data['results']:
                if 'words' in result:
                    for word_data in result['words']:
                        segments.append(self._create_word_segment(word_data))
        
        elif isinstance(transcription_data, list):
            # Direct array of word objects
            for word_data in transcription_data:
                segments.append(self._create_word_segment(word_data))
        
        return segments

    def _create_word_segment(self, word_data: Dict[str, Any]) -> WordSegment:
        """Create WordSegment from various JSON formats"""
        # Handle different field names
        word = (word_data.get('word') or 
                word_data.get('text') or 
                word_data.get('token') or '').strip()
        
        start = (word_data.get('start') or 
                word_data.get('start_time') or 
                word_data.get('begin') or 0)
        
        end = (word_data.get('end') or 
               word_data.get('end_time') or 
               word_data.get('finish') or 0)
        
        confidence = (word_data.get('confidence') or 
                     word_data.get('score') or 
                     word_data.get('probability') or 0)
        
        return WordSegment(word=word, start=start, end=end, confidence=confidence)

    def query_local_llm(self, word: str, context: str = "") -> bool:
        """Query local LLM to determine if word is guitar instruction terminology"""
        if word.lower() in self.evaluation_cache:
            return self.evaluation_cache[word.lower()]
        
        prompt = f"""
        You are an expert in guitar instruction and music education terminology.
        
        Word to evaluate: "{word}"
        Context: "{context}"
        
        Is this word specific to guitar instruction, guitar playing techniques, or guitar-related music theory?
        
        Consider terms like:
        - Playing techniques (strumming, picking, fretting, etc.)
        - Guitar parts and hardware (frets, capo, bridge, etc.)
        - Music theory as applied to guitar (chords, scales, progressions, etc.)
        - Guitar-specific notation or instruction terms
        
        Respond with only "YES" if it's guitar instruction terminology, or "NO" if it's not.
        """
        
        try:
            response = requests.post(
                self.llm_endpoint,
                json={
                    "model": self.model_name,
                    "prompt": prompt,
                    "stream": False,
                    "options": {
                        "temperature": 0.1,
                        "num_predict": 10
                    }
                },
                timeout=30
            )
            
            if response.status_code == 200:
                result = response.json()
                llm_response = result.get('response', '').strip().upper()
                is_guitar_term = "YES" in llm_response
                
                self.evaluation_cache[word.lower()] = is_guitar_term
                return is_guitar_term
            
        except Exception as e:
            print(f"LLM query failed for '{word}': {e}")
        
        # Fallback to known terms
        return word.lower() in self.known_guitar_terms

    def get_context_window(self, segments: List[WordSegment], index: int, window_size: int = 3) -> str:
        """Get surrounding words for context"""
        start_idx = max(0, index - window_size)
        end_idx = min(len(segments), index + window_size + 1)
        context_words = [seg.word for seg in segments[start_idx:end_idx]]
        return " ".join(context_words)

    def evaluate_and_boost(self, transcription_json: Dict[str, Any]) -> Dict[str, Any]:
        """
        Main method: evaluate guitar terms and boost their confidence scores
        """
        print("Extracting words from transcription...")
        segments = self.extract_words_from_json(transcription_json)
        print(f"Found {len(segments)} word segments")
        
        boosted_count = 0
        
        for i, segment in enumerate(segments):
            if not segment.word or len(segment.word.strip()) < 2:
                continue
            
            segment.original_confidence = segment.confidence
            
            # Only evaluate low-confidence words
            if segment.confidence < self.confidence_threshold:
                context = self.get_context_window(segments, i)
                
                if self.query_local_llm(segment.word, context):
                    new_confidence = min(1.0, segment.confidence + self.boost_amount)
                    segment.confidence = new_confidence
                    boosted_count += 1
                    print(f"Boosted '{segment.word}': {segment.original_confidence:.2f} -> {new_confidence:.2f}")
        
        print(f"Boosted confidence for {boosted_count} guitar terms")
        
        # Update the original JSON structure
        return self._update_json_with_new_confidence(transcription_json, segments)

    def _update_json_with_new_confidence(self, original_json: Dict[str, Any], 
                                       segments: List[WordSegment]) -> Dict[str, Any]:
        """Update original JSON with new confidence scores"""
        updated_json = json.loads(json.dumps(original_json))  # Deep copy
        
        segment_idx = 0
        
        def update_words_in_structure(data):
            nonlocal segment_idx
            
            if isinstance(data, dict):
                if 'words' in data:
                    for word_data in data['words']:
                        if segment_idx < len(segments):
                            seg = segments[segment_idx]
                            # Update confidence
                            if 'confidence' in word_data:
                                word_data['confidence'] = seg.confidence
                            elif 'score' in word_data:
                                word_data['score'] = seg.confidence
                            elif 'probability' in word_data:
                                word_data['probability'] = seg.confidence
                            
                            # Add metadata if boosted
                            if seg.original_confidence != seg.confidence:
                                word_data['confidence_boosted'] = True
                                word_data['original_confidence'] = seg.original_confidence
                            
                            segment_idx += 1
                
                for value in data.values():
                    update_words_in_structure(value)
            
            elif isinstance(data, list):
                for item in data:
                    update_words_in_structure(item)
        
        update_words_in_structure(updated_json)
        return updated_json

# Simple usage example
def process_file(input_path: str, output_path: str = None, **kwargs):
    """Process a transcription JSON file"""
    evaluator = GuitarTerminologyEvaluator(**kwargs)
    
    with open(input_path, 'r') as f:
        transcription_data = json.load(f)
    
    improved_data = evaluator.evaluate_and_boost(transcription_data)
    
    if output_path:
        with open(output_path, 'w') as f:
            json.dump(improved_data, f, indent=2)
        print(f"Saved improved transcription to: {output_path}")
    
    return improved_data

# CLI interface
if __name__ == "__main__":
    import argparse
    
    parser = argparse.ArgumentParser(description="Boost confidence for guitar terms in transcription JSON")
    parser.add_argument("input_file", help="Input transcription JSON file")
    parser.add_argument("-o", "--output", help="Output file path")
    parser.add_argument("--threshold", type=float, default=0.7, help="Confidence threshold")
    parser.add_argument("--boost", type=float, default=0.2, help="Boost amount")
    parser.add_argument("--llm-endpoint", default="http://localhost:11434/api/generate")
    parser.add_argument("--model", default="llama2", help="LLM model name")
    
    args = parser.parse_args()
    
    output_path = args.output or args.input_file.replace('.json', '_improved.json')
    
    process_file(
        args.input_file, 
        output_path,
        confidence_threshold=args.threshold,
        boost_amount=args.boost,
        llm_endpoint=args.llm_endpoint,
        model_name=args.model
    )
```

## Setup Requirements

### Dependencies
```bash
pip install requests
```

### Local LLM Setup (Ollama Example)
```bash
# Install Ollama
curl -fsSL https://ollama.ai/install.sh | sh

# Pull a model
ollama pull llama2

# Start Ollama server
ollama serve
```

### Alternative Local LLM Options
- **Ollama**: Easiest setup, good model selection
- **LM Studio**: GUI-based local LLM server
- **text-generation-webui**: Advanced features, API compatible
- **vLLM**: High-performance inference server

## Usage Examples

### Basic Usage
```bash
# Process a transcription file
python guitar_evaluator.py transcription.json

# With custom output path
python guitar_evaluator.py transcription.json -o improved_transcription.json
```

### Advanced Usage
```bash
# Custom thresholds and boost amounts
python guitar_evaluator.py transcription.json \
  --threshold 0.6 \
  --boost 0.3 \
  --model llama2 \
  --llm-endpoint http://localhost:11434/api/generate
```

### Programmatic Usage
```python
from guitar_evaluator import GuitarTerminologyEvaluator

evaluator = GuitarTerminologyEvaluator(
    confidence_threshold=0.6,
    boost_amount=0.25
)

with open('transcription.json', 'r') as f:
    data = json.load(f)

improved_data = evaluator.evaluate_and_boost(data)
```

## Configuration Options

| Parameter | Default | Description |
|-----------|---------|-------------|
| `confidence_threshold` | 0.7 | Below this score, words are evaluated |
| `boost_amount` | 0.2 | How much to increase confidence |
| `llm_endpoint` | `http://localhost:11434/api/generate` | Local LLM API endpoint |
| `model_name` | `llama2` | LLM model to use |

## JSON Format Compatibility

The evaluator automatically detects and works with various JSON structures:

### Whisper Format
```json
{
  "segments": [
    {
      "words": [
        {"word": "fret", "start": 1.2, "end": 1.5, "confidence": 0.65}
      ]
    }
  ]
}
```

### Direct Words Format
```json
{
  "words": [
    {"text": "chord", "start_time": 2.1, "end_time": 2.4, "score": 0.45}
  ]
}
```

### Results Format
```json
{
  "results": [
    {
      "words": [
        {"token": "progression", "begin": 2.5, "finish": 3.1, "probability": 0.55}
      ]
    }
  ]
}
```

## Guitar Terms Dictionary

The system includes a comprehensive fallback dictionary of guitar terms:

**Playing Techniques**: strumming, picking, fingerpicking, flatpicking, alternate, sweep, tapping, hammer, pulloff, slide, bend, vibrato

**Guitar Parts**: fret, fretboard, strings, bridge, saddle, nut, headstock, tuners, capo

**Music Theory**: chord, scale, progression, arpeggio, major, minor, seventh, diminished, augmented, pentatonic

**Equipment**: guitar, acoustic, electric, bass, amp, amplifier, distortion, overdrive, tremolo

## Performance Considerations

- **Caching**: Terms are cached after first evaluation to avoid redundant LLM calls
- **Batch Processing**: Consider processing multiple files in batch for efficiency  
- **LLM Response Time**: Local LLMs typically respond in 1-3 seconds per query
- **Memory Usage**: Minimal memory footprint, processes files sequentially

## Future Enhancements

1. **Batch LLM Queries**: Send multiple terms in single request
2. **Advanced Context Analysis**: Use sentence-level context
3. **Domain Expansion**: Support other music instruction domains
4. **Confidence Calibration**: Learn optimal boost amounts from data
5. **Term Learning**: Automatically expand known terms dictionary
6. **Multi-language Support**: Handle non-English guitar instruction

## Testing Strategy

1. **Unit Tests**: Test individual components
2. **Integration Tests**: Test with various JSON formats
3. **Performance Tests**: Measure processing speed and accuracy
4. **Domain Tests**: Validate guitar term recognition accuracy

## Implementation Notes

- The solution is designed to be **non-destructive** - original data is preserved
- **Flexible architecture** allows easy adaptation to new transcription services  
- **Offline capability** through fallback dictionary ensures reliability
- **CLI and programmatic interfaces** support different usage scenarios
</rewritten_file> 