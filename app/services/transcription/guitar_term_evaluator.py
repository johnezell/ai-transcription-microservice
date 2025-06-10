import json
import requests
from typing import Dict, List, Any, Optional
from dataclasses import dataclass
import logging

logger = logging.getLogger(__name__)

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
                 confidence_threshold: float = 0.99,  # Set high since we want to boost all musical terms
                 target_confidence: float = 1.0):    # Set to 100% as requested
        self.llm_endpoint = llm_endpoint
        self.model_name = model_name
        self.confidence_threshold = confidence_threshold
        self.target_confidence = target_confidence
        self.evaluation_cache = {}
        
        # Load comprehensive guitar terms library
        try:
            from guitar_terms_library import get_guitar_terms_library
            self.guitar_library = get_guitar_terms_library()
            logger.info(f"Loaded comprehensive guitar library with {len(self.guitar_library.get_all_terms())} terms")
        except ImportError:
            logger.warning("Guitar terms library not available, using basic fallback")
            self.guitar_library = None
            
        # Basic fallback guitar terms (used if library import fails)
        self.basic_guitar_terms = {
            "fret", "frets", "fretting", "fretboard", "chord", "chords", 
            "strumming", "picking", "capo", "tuning", "tablature", "tab", 
            "hammer-on", "pull-off", "slide", "bend", "vibrato", "fingerpicking",
            "progression", "arpeggio", "scale", "pentatonic", "major", "minor", 
            "seventh", "guitar", "acoustic", "electric", "bass", "amp", 
            "distortion", "overdrive", "tremolo", "bridge", "pickup", "strings"
        }

    def extract_words_from_json(self, transcription_data: Dict[str, Any]) -> List[WordSegment]:
        """Extract word segments from transcription JSON (adapted to your service format)"""
        segments = []
        
        # Your service uses 'word_segments' at the top level
        if 'word_segments' in transcription_data:
            for word_data in transcription_data['word_segments']:
                segments.append(self._create_word_segment(word_data))
        
        # Also check segments->words structure for completeness
        elif 'segments' in transcription_data:
            for segment in transcription_data['segments']:
                if 'words' in segment:
                    for word_data in segment['words']:
                        segments.append(self._create_word_segment(word_data))
        
        return segments

    def _create_word_segment(self, word_data: Dict[str, Any]) -> WordSegment:
        """Create WordSegment from your service's JSON format"""
        word = word_data.get('word', '').strip()
        start = word_data.get('start', 0)
        end = word_data.get('end', 0)
        confidence = word_data.get('score', word_data.get('confidence', 0))
        
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
        - Playing techniques (strumming, picking, fretting, hammer-on, pull-off, etc.)
        - Guitar parts and hardware (frets, capo, bridge, pickup, strings, etc.)
        - Music theory as applied to guitar (chords, scales, progressions, etc.)
        - Guitar-specific notation or instruction terms
        - Musical notes and chord names (C, D, E, F, G, A, B, sharp, flat, major, minor, etc.)
        - Guitar techniques and effects
        
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
                timeout=15  # Reduced timeout for performance
            )
            
            if response.status_code == 200:
                result = response.json()
                llm_response = result.get('response', '').strip().upper()
                is_guitar_term = "YES" in llm_response
                
                self.evaluation_cache[word.lower()] = is_guitar_term
                return is_guitar_term
            
        except Exception as e:
            logger.warning(f"LLM query failed for '{word}': {e}")
        
        # Fallback to comprehensive library or basic terms
        if self.guitar_library:
            return self.guitar_library.is_guitar_term(word)
        else:
            return word.lower() in self.basic_guitar_terms

    def get_context_window(self, segments: List[WordSegment], index: int, window_size: int = 3) -> str:
        """Get surrounding words for context"""
        start_idx = max(0, index - window_size)
        end_idx = min(len(segments), index + window_size + 1)
        context_words = [seg.word for seg in segments[start_idx:end_idx]]
        return " ".join(context_words)

    def evaluate_and_boost(self, transcription_json: Dict[str, Any]) -> Dict[str, Any]:
        """
        Main method: evaluate guitar terms and set their confidence to 100%
        """
        logger.info("Starting guitar terminology evaluation...")
        segments = self.extract_words_from_json(transcription_json)
        logger.info(f"Found {len(segments)} word segments for evaluation")
        
        boosted_count = 0
        unchanged_count = 0
        evaluated_terms = []
        
        for i, segment in enumerate(segments):
            if not segment.word or len(segment.word.strip()) < 2:
                continue
            
            # Always preserve original confidence
            segment.original_confidence = segment.confidence
            
            # Evaluate all words, not just low-confidence ones
            context = self.get_context_window(segments, i)
            
            if self.query_local_llm(segment.word, context):
                # ONLY guitar terms get boosted to 100% confidence
                segment.confidence = self.target_confidence
                boosted_count += 1
                evaluated_terms.append({
                    'word': segment.word,
                    'original_confidence': segment.original_confidence,
                    'new_confidence': segment.confidence,
                    'start': segment.start,
                    'end': segment.end
                })
                logger.debug(f"Boosted guitar term '{segment.word}': {segment.original_confidence:.2f} -> {segment.confidence:.2f}")
            else:
                # Non-guitar terms are left completely unchanged at their original confidence
                unchanged_count += 1
                logger.debug(f"Left unchanged '{segment.word}': {segment.original_confidence:.2f} (not a guitar term)")
        
        logger.info(f"Enhanced confidence for {boosted_count} guitar terms, left {unchanged_count} non-guitar terms unchanged")
        
        # Update the original JSON structure with enhanced confidence scores
        updated_json = self._update_json_with_new_confidence(transcription_json, segments)
        
        # Get library statistics
        library_stats = {}
        if self.guitar_library:
            library_stats = self.guitar_library.get_statistics()
            library_stats['library_type'] = 'comprehensive'
        else:
            library_stats = {
                'total_terms': len(self.basic_guitar_terms),
                'library_type': 'basic_fallback'
            }
        
        # Add evaluation metadata
        updated_json['guitar_term_evaluation'] = {
            'evaluator_version': '2.0',
            'total_words_evaluated': len(segments),
            'musical_terms_found': boosted_count,
            'non_musical_terms_unchanged': unchanged_count,
            'target_confidence': self.target_confidence,
            'evaluation_timestamp': json.dumps(evaluated_terms, default=str),  # For serialization
            'enhanced_terms': evaluated_terms,
            'llm_used': self.model_name,
            'cache_hits': len(self.evaluation_cache),
            'library_statistics': library_stats,
            'note': 'Only guitar terms are boosted to 100%, all other words keep original confidence'
        }
        
        return updated_json

    def _update_json_with_new_confidence(self, original_json: Dict[str, Any], 
                                       segments: List[WordSegment]) -> Dict[str, Any]:
        """Update original JSON with new confidence scores, preserving structure"""
        updated_json = json.loads(json.dumps(original_json))  # Deep copy
        
        segment_idx = 0
        
        # Update word_segments at top level (your service format)
        if 'word_segments' in updated_json:
            for word_data in updated_json['word_segments']:
                if segment_idx < len(segments):
                    seg = segments[segment_idx]
                    
                    # Preserve original confidence
                    word_data['original_confidence'] = seg.original_confidence
                    
                    # Update current confidence
                    if 'score' in word_data:
                        word_data['score'] = seg.confidence
                    if 'confidence' in word_data:
                        word_data['confidence'] = seg.confidence
                    
                    # Add metadata if boosted
                    if seg.original_confidence != seg.confidence:
                        word_data['guitar_term_boosted'] = True
                        word_data['boost_reason'] = 'musical_terminology'
                    
                    segment_idx += 1
        
        # Also update segments->words structure for consistency
        segment_idx = 0  # Reset for segments structure
        if 'segments' in updated_json:
            for segment in updated_json['segments']:
                if 'words' in segment:
                    for word_data in segment['words']:
                        if segment_idx < len(segments):
                            seg = segments[segment_idx]
                            
                            # Preserve original confidence
                            word_data['original_confidence'] = seg.original_confidence
                            
                            # Update current confidence
                            if 'score' in word_data:
                                word_data['score'] = seg.confidence
                            if 'confidence' in word_data:
                                word_data['confidence'] = seg.confidence
                            
                            # Add metadata if boosted
                            if seg.original_confidence != seg.confidence:
                                word_data['guitar_term_boosted'] = True
                                word_data['boost_reason'] = 'musical_terminology'
                            
                            segment_idx += 1
        
        return updated_json

# Simple usage function for integration
def enhance_guitar_terminology(transcription_result: Dict[str, Any], 
                             llm_endpoint: str = "http://localhost:11434/api/generate",
                             model_name: str = "llama2",
                             confidence_threshold: float = 0.99,
                             target_confidence: float = 1.0) -> Dict[str, Any]:
    """
    Simple function to enhance guitar terminology in transcription results
    
    Args:
        transcription_result: The transcription result dictionary
        llm_endpoint: LLM API endpoint for term evaluation
        model_name: LLM model name to use
        confidence_threshold: Only evaluate words below this threshold
        target_confidence: Set guitar terms to this confidence level
        
    Returns:
        Enhanced transcription result with boosted guitar term confidence
    """
    try:
        evaluator = GuitarTerminologyEvaluator(
            llm_endpoint=llm_endpoint,
            model_name=model_name,
            confidence_threshold=confidence_threshold,
            target_confidence=target_confidence
        )
        
        enhanced_result = evaluator.evaluate_and_boost(transcription_result)
        
        # Log library information
        eval_data = enhanced_result.get('guitar_term_evaluation', {})
        library_stats = eval_data.get('library_statistics', {})
        library_type = library_stats.get('library_type', 'unknown')
        total_terms = library_stats.get('total_terms', 0)
        
        logger.info(f"Guitar terminology enhancement completed successfully using {library_type} library with {total_terms} terms")
        
        return enhanced_result
        
    except Exception as e:
        logger.error(f"Guitar terminology enhancement failed: {e}")
        # Return original result if enhancement fails
        return transcription_result

# Convenience function to get library information
def get_guitar_terms_library_info() -> Dict[str, Any]:
    """Get information about the loaded guitar terms library"""
    try:
        from guitar_terms_library import get_guitar_terms_library
        library = get_guitar_terms_library()
        stats = library.get_statistics()
        return {
            'available': True,
            'type': 'comprehensive',
            'statistics': stats,
            'total_terms': stats.get('total_terms', 0)
        }
    except ImportError:
        return {
            'available': False,
            'type': 'basic_fallback',
            'total_terms': len({
                "fret", "frets", "fretting", "fretboard", "chord", "chords", 
                "strumming", "picking", "capo", "tuning", "tablature", "tab", 
                "hammer-on", "pull-off", "slide", "bend", "vibrato", "fingerpicking",
                "progression", "arpeggio", "scale", "pentatonic", "major", "minor", 
                "seventh", "guitar", "acoustic", "electric", "bass", "amp", 
                "distortion", "overdrive", "tremolo", "bridge", "pickup", "strings"
            })
        } 