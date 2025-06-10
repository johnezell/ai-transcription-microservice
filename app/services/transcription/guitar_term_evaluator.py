import json
import requests
from typing import Dict, List, Any, Optional
from dataclasses import dataclass
import logging
import re
import os

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
                 llm_endpoint: str = None,
                 model_name: str = None,
                 confidence_threshold: float = 0.75,  # Only evaluate words below this confidence
                 target_confidence: float = 1.0):    # Set to 100% as requested
        
        # Use environment variables with fallbacks for Docker container networking
        self.llm_endpoint = llm_endpoint or os.getenv('LLM_ENDPOINT', 'http://host.docker.internal:11434/api/generate')
        self.model_name = model_name or os.getenv('LLM_MODEL', 'llama2')
        self.llm_enabled = os.getenv('LLM_ENABLED', 'true').lower() == 'true'
        
        self.confidence_threshold = confidence_threshold
        self.target_confidence = target_confidence
        self.evaluation_cache = {}
        
        logger.info(f"Guitar Term Evaluator initialized - LLM: {self.llm_endpoint}, Model: {self.model_name}, Enabled: {self.llm_enabled}")
        
        # Load comprehensive guitar terms library
        try:
            from guitar_terms_library import get_guitar_terms_library
            self.guitar_library = get_guitar_terms_library()
            logger.info(f"Loaded comprehensive guitar library with {len(self.guitar_library.get_all_terms())} terms")
        except ImportError:
            logger.warning("Guitar terms library not available, using basic fallback")
            self.guitar_library = None
            
        # Enhanced basic fallback guitar terms (updated with more terms including fingerstyle)
        self.basic_guitar_terms = {
            "fret", "frets", "fretting", "fretboard", "chord", "chords", 
            "strumming", "picking", "capo", "tuning", "tablature", "tab", 
            "hammer-on", "pull-off", "slide", "bend", "vibrato", "fingerpicking",
            "fingerstyle", "flatpicking", "alternate", "downstroke", "upstroke",
            "progression", "arpeggio", "scale", "pentatonic", "major", "minor", 
            "seventh", "guitar", "acoustic", "electric", "bass", "amp", 
            "distortion", "overdrive", "tremolo", "bridge", "pickup", "strings",
            "sharp", "flat", "natural", "diminished", "augmented", "suspended",
            "barre", "open", "mute", "harmonics", "tapping", "palm", "muting"
        }
        
        # CRITICAL: Common words that should NEVER be enhanced as guitar terms
        # These are frequently misclassified by LLMs and must be explicitly filtered out
        self.common_words_blacklist = {
            "the", "and", "or", "but", "so", "if", "then", "when", "where", "why", "how",
            "to", "from", "in", "on", "at", "by", "for", "with", "without", "of", "off",
            "a", "an", "is", "are", "was", "were", "be", "been", "being", "have", "has", "had",
            "do", "does", "did", "will", "would", "could", "should", "may", "might", "can",
            "get", "got", "getting", "put", "take", "make", "go", "come", "see", "look",
            "this", "that", "these", "those", "here", "there", "now", "then", "today",
            "you", "your", "yours", "we", "our", "ours", "he", "his", "she", "her", "hers",
            "it", "its", "they", "their", "theirs", "me", "my", "mine", "us", "him", "them",
            "up", "down", "out", "over", "under", "through", "into", "onto", "across",
            "very", "really", "quite", "pretty", "much", "many", "more", "most", "less", "least",
            "good", "bad", "big", "small", "new", "old", "first", "last", "next", "other",
            "right", "left", "back", "front", "high", "low", "long", "short", "wide", "narrow"
        }
    
    def _normalize_word(self, word: str) -> str:
        """Normalize word by removing punctuation and converting to lowercase"""
        if not word:
            return ""
        
        # Remove common punctuation but preserve hyphens in compound terms
        # Remove: . , ! ? : ; " ' ( ) [ ] { }
        # Keep: - (for terms like "hammer-on", "pull-off")
        normalized = re.sub(r'[.,:;!?"\'()\[\]{}]', '', word)
        normalized = normalized.strip().lower()
        
        return normalized

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
        # Normalize word for caching and evaluation
        normalized_word = self._normalize_word(word)
        
        if normalized_word in self.evaluation_cache:
            logger.debug(f"Cache hit for '{word}' (normalized: '{normalized_word}')")
            return self.evaluation_cache[normalized_word]
        
        # CRITICAL: Check blacklist first - never enhance common words
        if normalized_word in self.common_words_blacklist:
            logger.debug(f"Blacklisted common word '{word}' (normalized: '{normalized_word}') - will not enhance")
            self.evaluation_cache[normalized_word] = False
            return False
        
        # Check comprehensive library first (faster and more reliable than LLM)
        if self.guitar_library:
            is_guitar_term = self.guitar_library.is_guitar_term(normalized_word)
            if is_guitar_term:
                logger.debug(f"Guitar library confirmed '{word}' (normalized: '{normalized_word}') as guitar term")
                self.evaluation_cache[normalized_word] = True
                return True
        
        # Check basic fallback terms
        if normalized_word in self.basic_guitar_terms:
            logger.debug(f"Basic fallback confirmed '{word}' (normalized: '{normalized_word}') as guitar term")
            self.evaluation_cache[normalized_word] = True
            return True
        
        # Only query LLM if enabled and available for unknown terms
        if self.llm_enabled:
            try:
                prompt = f"""
                You are an expert in guitar instruction and music education terminology.
                
                Word to evaluate: "{word}"
                Context: "{context}"
                
                Is this word specific to guitar instruction, guitar playing techniques, or guitar-related music theory?
                
                INCLUDE these types of terms:
                - Playing techniques (strumming, picking, fretting, hammer-on, pull-off, fingerstyle, etc.)
                - Guitar parts and hardware (frets, capo, bridge, pickup, strings, etc.)
                - Music theory as applied to guitar (chords, scales, progressions, etc.)
                - Guitar-specific notation or instruction terms
                - Musical notes and chord names (C, D, E, F, G, A, B, sharp, flat, major, minor, etc.)
                - Guitar techniques and effects
                
                DO NOT INCLUDE common words like:
                - Articles (a, an, the)
                - Prepositions (in, on, at, to, from, with, by, for, of)
                - Pronouns (you, your, I, my, we, our, he, his, she, her, they, their)
                - Common verbs (is, are, was, were, have, has, had, do, does, did, get, got, put, take, make, go, come)
                - General adjectives (good, bad, big, small, new, old, right, left, high, low)
                - Common adverbs (very, really, quite, now, then, here, there)
                
                ONLY respond with "YES" for genuine guitar/music terminology. Respond "NO" for all common words.
                """
                
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
                    
                    # More strict parsing: must start with YES or be exactly YES
                    # This prevents false positives from responses like "NO, this is not a YES-worthy term"
                    is_guitar_term = llm_response.startswith("YES") or llm_response == "YES"
                    
                    logger.debug(f"LLM evaluation for '{word}' (normalized: '{normalized_word}'): response='{llm_response}', result={is_guitar_term}")
                    self.evaluation_cache[normalized_word] = is_guitar_term
                    return is_guitar_term
                else:
                    logger.warning(f"LLM request failed with status {response.status_code} for word '{word}' - falling back to library-only mode")
                
            except Exception as e:
                logger.debug(f"LLM query failed for '{word}': {e} - using library-only evaluation")
        else:
            logger.debug(f"LLM disabled - using library-only evaluation for '{word}'")
        
        # Final fallback: conservative approach - don't boost unknown terms
        logger.debug(f"Term '{word}' (normalized: '{normalized_word}') not found in guitar libraries, marking as non-guitar term")
        self.evaluation_cache[normalized_word] = False
        return False

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
        blacklisted_count = 0
        evaluated_terms = []
        
        for i, segment in enumerate(segments):
            if not segment.word or len(segment.word.strip()) < 2:
                continue
            
            # Always preserve original confidence
            segment.original_confidence = segment.confidence
            
            # Only evaluate words with confidence below threshold (performance optimization)
            if segment.confidence >= self.confidence_threshold:
                unchanged_count += 1
                logger.debug(f"Skipped '{segment.word}': {segment.original_confidence:.3f} (confidence already high)")
                continue
            
            # Check if word is blacklisted before evaluation
            normalized_word = self._normalize_word(segment.word)
            if normalized_word in self.common_words_blacklist:
                blacklisted_count += 1
                logger.debug(f"Blacklisted common word '{segment.word}': {segment.original_confidence:.3f} (filtered out)")
                continue
            
            # Evaluate low-confidence words to see if they're guitar terms
            context = self.get_context_window(segments, i)
            
            if self.query_local_llm(segment.word, context):
                # ONLY guitar terms get boosted to 100% confidence
                segment.confidence = self.target_confidence
                boosted_count += 1
                evaluated_terms.append({
                    'word': segment.word,
                    'normalized_word': self._normalize_word(segment.word),
                    'original_confidence': segment.original_confidence,
                    'new_confidence': segment.confidence,
                    'start': segment.start,
                    'end': segment.end
                })
                logger.debug(f"Boosted guitar term '{segment.word}': {segment.original_confidence:.3f} -> {segment.confidence:.3f}")
            else:
                # Non-guitar terms are left completely unchanged at their original confidence
                unchanged_count += 1
                logger.debug(f"Left unchanged '{segment.word}': {segment.original_confidence:.3f} (not a guitar term)")
        
        logger.info(f"Enhanced confidence for {boosted_count} guitar terms, left {unchanged_count} non-guitar terms unchanged, filtered out {blacklisted_count} common words")
        
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
            'evaluator_version': '2.4',  # Updated version with common word blacklist and 75% threshold
            'total_words_evaluated': len(segments),
            'musical_terms_found': boosted_count,
            'non_musical_terms_unchanged': unchanged_count,
            'common_words_filtered': blacklisted_count,
            'confidence_threshold': self.confidence_threshold,
            'target_confidence': self.target_confidence,
            'evaluation_timestamp': json.dumps(evaluated_terms, default=str),  # For serialization
            'enhanced_terms': evaluated_terms,
            'llm_configuration': {
                'endpoint': self.llm_endpoint,
                'model': self.model_name,
                'enabled': self.llm_enabled,
                'cache_hits': len(self.evaluation_cache)
            },
            'library_statistics': library_stats,
            'punctuation_handling': 'enabled',
            'confidence_filtering': 'enabled',  # New: only evaluate low-confidence words
            'strict_llm_parsing': 'enabled',   # New: strict YES/NO parsing
            'common_word_blacklist': 'enabled',  # New: filter out common words
            'docker_networking': 'configured',
            'note': f'Only low-confidence words (< {self.confidence_threshold:.0%}) are evaluated. Common words are blacklisted. Guitar terms are boosted to 100%, others keep original confidence. Enhanced with strict LLM response parsing and common word filtering.'
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
                        word_data['normalized_form'] = self._normalize_word(seg.word)
                    
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
                                word_data['normalized_form'] = self._normalize_word(seg.word)
                            
                            segment_idx += 1
        
        return updated_json

# Simple usage function for integration
def enhance_guitar_terminology(transcription_result: Dict[str, Any], 
                             llm_endpoint: str = None,
                             model_name: str = None,
                             confidence_threshold: float = 0.75,
                             target_confidence: float = 1.0) -> Dict[str, Any]:
    """
    Simple function to enhance guitar terminology in transcription results
    
    Args:
        transcription_result: The transcription result dictionary
        llm_endpoint: LLM API endpoint for term evaluation
        model_name: LLM model name to use
        confidence_threshold: Only evaluate words below this confidence (default 0.75)
        target_confidence: Set guitar terms to this confidence level (default 1.0)
        
    Returns:
        Enhanced transcription result with boosted guitar term confidence.
        Only low-confidence words are evaluated. Common words are blacklisted to prevent false positives.
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