import json
import requests
from typing import Dict, List, Any, Optional
from dataclasses import dataclass
import logging
import re
import os

# Try to import dictionary checking capability
try:
    import enchant
    ENCHANT_AVAILABLE = True
except ImportError:
    ENCHANT_AVAILABLE = False

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
        
        # Initialize dictionary checker for common English words
        self.dictionary = None
        if ENCHANT_AVAILABLE:
            try:
                self.dictionary = enchant.Dict("en_US")
                logger.info("English dictionary loaded for common word filtering")
            except Exception as e:
                logger.warning(f"Could not load English dictionary: {e}")
        else:
            logger.warning("enchant library not available - using fallback word filtering")
        
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
    
    def _normalize_word(self, word: str) -> str:
        """
        Conservative normalization for musical contexts - preserves meaningful special characters.
        Used for caching and library lookups, NOT for LLM queries (LLM gets original word).
        """
        if not word:
            return ""
        
        # Very conservative normalization for musical terminology
        # Remove only clearly irrelevant punctuation: . , ! ? : ; " ( ) [ ] { }
        # PRESERVE meaningful musical characters: - _ ' # + (for musical notation)
        # Keep: - (hammer-on, pull-off), _ (file_names, chord_progressions), 
        #       ' (contractions), # (C#, F#), + (augmented chords)
        normalized = re.sub(r'[.,:;!?"()\[\]{}]', '', word)
        normalized = normalized.strip().lower()
        
        return normalized

    def _normalize_for_cache(self, word: str) -> str:
        """
        Create a cache key that preserves musical meaning while being consistent.
        This is used for LLM response caching to avoid re-querying the same musical terms.
        """
        if not word:
            return ""
        
        # Minimal normalization for cache keys - just case and whitespace
        # PRESERVE ALL special characters that might be musically meaningful
        return word.strip().lower()

    def _is_contraction(self, word: str) -> bool:
        """Check if a word contains an apostrophe indicating a contraction"""
        return "'" in word and len(word) > 2

    def _is_compound_word(self, word: str) -> bool:
        """Check if a word contains hyphens or underscores indicating a compound word"""
        return ("-" in word or "_" in word) and len(word) > 3

    def _expand_contraction(self, word: str) -> List[str]:
        """Expand contractions into component words for dictionary checking"""
        if not self._is_contraction(word):
            return [word]
        
        # Split on apostrophe and handle common patterns
        parts = word.lower().split("'")
        if len(parts) != 2:
            return [word]  # Not a simple contraction
        
        base, suffix = parts
        
        # Common contraction patterns with multiple possible expansions
        expansions = {
            's': [base + ' is', base + ' has'],          # "here's" -> ["here is", "here has"]
            're': [base + ' are'],                       # "you're" -> ["you are"]  
            'll': [base + ' will'],                      # "you'll" -> ["you will"]
            'd': [base + ' would', base + ' had'],       # "you'd" -> ["you would", "you had"]
            've': [base + ' have'],                      # "you've" -> ["you have"]
            't': [base + ' not'],                        # "don't" -> ["do not"], "can't" -> ["can not"]
            'm': [base + ' am'],                         # "I'm" -> ["I am"]
        }
        
        possible_expansions = expansions.get(suffix, [])
        
        # If no standard expansion, return the base word (might be a possessive)
        if not possible_expansions:
            return [base]  # "John's" -> ["john"]
        
        return possible_expansions

    def _check_contraction_parts(self, word: str) -> bool:
        """Check if a contraction expands to common English words"""
        if not self._is_contraction(word):
            return False
        
        expansions = self._expand_contraction(word)
        
        # Check if any expansion consists of all dictionary words
        for expansion in expansions:
            words = expansion.split()
            if self.dictionary:
                # All words in expansion must be in dictionary
                if all(self.dictionary.check(w) for w in words):
                    return True
            else:
                # Fallback: check against common words
                common_words = {
                    "i", "you", "he", "she", "it", "we", "they", "here", "there", "what", "that",
                    "who", "how", "when", "where", "is", "are", "am", "was", "were", "have", "has", "had",
                    "will", "would", "could", "should", "might", "may", "can", "do", "does", "did", "not"
                }
                if all(w in common_words for w in words):
                    return True
        
        return False

    def _split_compound_word(self, word: str) -> List[str]:
        """Split compound words on hyphens and underscores"""
        if not self._is_compound_word(word):
            return [word]
        
        # Split on both hyphens and underscores
        # Replace underscores with hyphens first, then split on hyphens
        normalized = word.replace('_', '-')
        parts = normalized.split('-')
        
        # Filter out empty parts and very short parts (likely not real words)
        valid_parts = [part.strip() for part in parts if len(part.strip()) > 1]
        
        return valid_parts if valid_parts else [word]

    def _check_compound_parts(self, word: str) -> bool:
        """Check if a compound word's parts are all common English words"""
        if not self._is_compound_word(word):
            return False
        
        # IMPORTANT: Check if it's a known guitar compound term first
        # These should NOT be split because they're specialized terminology
        guitar_compound_terms = {
            "hammer-on", "pull-off", "pick-up", "set-up", "tune-up", 
            "warm-up", "cool-down", "step-up", "step-down", "break-down",
            "build-up", "fade-in", "fade-out", "cut-off", "cut-through"
        }
        
        if word.lower() in guitar_compound_terms:
            return False  # Don't treat guitar terms as common English
        
        parts = self._split_compound_word(word)
        
        if len(parts) < 2:
            return False  # Not really a compound word
        
        # Check if all parts are common English words
        if self.dictionary:
            # Use dictionary if available
            return all(self.dictionary.check(part.lower()) for part in parts)
        else:
            # Fallback: check against common words
            common_words = {
                "the", "and", "or", "but", "so", "if", "then", "when", "where", "why", "how",
                "to", "from", "in", "on", "at", "by", "for", "with", "without", "of", "off",
                "a", "an", "is", "are", "was", "were", "be", "been", "being", "have", "has", "had",
                "do", "does", "did", "will", "would", "could", "should", "may", "might", "can",
                "get", "got", "getting", "put", "take", "make", "go", "come", "see", "look",
                "this", "that", "these", "those", "here", "there", "now", "then", "today",
                "you", "your", "yours", "we", "our", "ours", "he", "his", "she", "her", "hers",
                "it", "its", "they", "their", "theirs", "me", "my", "mine", "us", "him", "them",
                "up", "down", "over", "under", "through", "around", "between", "among", "within",
                "state", "art", "well", "known", "high", "low", "good", "bad", "best", "better",
                "first", "last", "next", "back", "front", "side", "top", "bottom", "left", "right"
            }
            return all(part.lower() in common_words for part in parts)

    def _is_common_english_word(self, word: str) -> bool:
        """Check if word is a common English dictionary word using intelligent contraction detection"""
        if not word or len(word) < 2:
            return True  # Very short words are usually common
        
        original_word = word.strip().lower()
        normalized_word = self._normalize_word(word)
        
        # STEP 1: Check if it's a contraction with apostrophe pattern
        if self._is_contraction(original_word):
            return self._check_contraction_parts(original_word)
        
        # STEP 2: Check if it's a compound word with hyphen/underscore pattern
        if self._is_compound_word(original_word):
            return self._check_compound_parts(original_word)
        
        # STEP 3: Use dictionary if available for the normalized word
        if self.dictionary:
            if self.dictionary.check(normalized_word):
                return True
        
        # STEP 4: Fallback to basic common words list (for cases where dictionary isn't available)
        very_common_words = {
            "the", "and", "or", "but", "so", "if", "then", "when", "where", "why", "how",
            "to", "from", "in", "on", "at", "by", "for", "with", "without", "of", "off",
            "a", "an", "is", "are", "was", "were", "be", "been", "being", "have", "has", "had",
            "do", "does", "did", "will", "would", "could", "should", "may", "might", "can",
            "get", "got", "getting", "put", "take", "make", "go", "come", "see", "look",
            "this", "that", "these", "those", "here", "there", "now", "then", "today",
            "you", "your", "yours", "we", "our", "ours", "he", "his", "she", "her", "hers",
            "it", "its", "they", "their", "theirs", "me", "my", "mine", "us", "him", "them"
        }
        
        return normalized_word in very_common_words

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
        # Use conservative cache key that preserves musical characters
        cache_key = self._normalize_for_cache(word)
        # Use regular normalization for library lookups (removes some punctuation)
        normalized_word = self._normalize_word(word)
        
        if cache_key in self.evaluation_cache:
            logger.debug(f"Cache hit for '{word}' (cache_key: '{cache_key}')")
            return self.evaluation_cache[cache_key]
        
        # STEP 1: Check comprehensive guitar library first (confirmed guitar terms)
        if self.guitar_library:
            is_guitar_term = self.guitar_library.is_guitar_term(normalized_word)
            if is_guitar_term:
                logger.debug(f"Guitar library confirmed '{word}' (normalized: '{normalized_word}') as guitar term")
                self.evaluation_cache[cache_key] = True
                return True
        
        # STEP 2: Check basic fallback guitar terms
        if normalized_word in self.basic_guitar_terms:
            logger.debug(f"Basic fallback confirmed '{word}' (normalized: '{normalized_word}') as guitar term")
            self.evaluation_cache[cache_key] = True
            return True
        
        # STEP 3: Check if it's a common English dictionary word - if yes, don't enhance
        if self._is_common_english_word(word):
            logger.debug(f"Dictionary word '{word}' (cache_key: '{cache_key}') - will not enhance common English word")
            self.evaluation_cache[cache_key] = False
            return False
        
        # STEP 4: Only query LLM for non-dictionary words that might be specialized guitar terms
        if self.llm_enabled:
            try:
                prompt = f"""
                You are an expert in guitar instruction and music education terminology.
                
                Word to evaluate: "{word}"
                Context: "{context}"
                
                This word is NOT in standard English dictionaries, so it might be specialized terminology.
                Is this word specific to guitar instruction, guitar playing techniques, or guitar-related music theory?
                
                INCLUDE these types of specialized terms:
                - Playing techniques: hammer-on, pull-off, sweep-picking, palm-muting, finger_picking
                - Musical notation: C#, F#, Bb, D♭, chord progressions like I-V-vi-IV
                - Guitar hardware: pick-up, set-up, tune-up, whammy_bar, tremolo_arm
                - Technical terms: tablature, fingerstyle, flatpicking, bottleneck
                - Effects and gear: overdrive, fuzz-box, delay_pedal, reverb_tank
                - Music theory: pentatonic, mixolydian, add9, sus4, maj7, dim7
                
                PRESERVE special characters like hyphens (-), underscores (_), sharps (#), flats (b), plus (+) 
                as they are often meaningful in musical terminology.
                
                Only respond "YES" for genuine specialized guitar/music terminology.
                Respond "NO" if it's likely a typo, foreign word, or unrelated technical term.
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
                    
                    logger.debug(f"LLM evaluation for original word '{word}': response='{llm_response}', result={is_guitar_term}")
                    self.evaluation_cache[cache_key] = is_guitar_term
                    return is_guitar_term
                else:
                    logger.warning(f"LLM request failed with status {response.status_code} for word '{word}' - falling back to library-only mode")
                
            except Exception as e:
                logger.debug(f"LLM query failed for '{word}': {e} - using library-only evaluation")
        else:
            logger.debug(f"LLM disabled - using library-only evaluation for '{word}'")
        
        # Final fallback: conservative approach - don't boost unknown terms
        logger.debug(f"Term '{word}' not found in guitar libraries and LLM unavailable/failed, marking as non-guitar term")
        self.evaluation_cache[cache_key] = False
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
        dictionary_filtered_count = 0
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
                # (This includes dictionary words filtered out by _is_common_english_word)
                unchanged_count += 1
                if self._is_common_english_word(segment.word):
                    dictionary_filtered_count += 1
                    logger.debug(f"Left unchanged dictionary word '{segment.word}': {segment.original_confidence:.3f} (common English word)")
                else:
                    logger.debug(f"Left unchanged '{segment.word}': {segment.original_confidence:.3f} (not a guitar term)")
        
        logger.info(f"Enhanced confidence for {boosted_count} guitar terms, left {unchanged_count} non-guitar terms unchanged ({dictionary_filtered_count} were dictionary words)")
        
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
            'evaluator_version': '3.2',  # Update: Conservative normalization preserves musical special characters
            'total_words_evaluated': len(segments),
            'musical_terms_found': boosted_count,
            'non_musical_terms_unchanged': unchanged_count,
            'dictionary_words_filtered': dictionary_filtered_count,
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
            'dictionary_configuration': {
                'dictionary_available': self.dictionary is not None,
                'enchant_available': ENCHANT_AVAILABLE,
                'filtering_method': 'dictionary_based' if self.dictionary else 'fallback_common_words'
            },
            'library_statistics': library_stats,
            'punctuation_handling': 'enabled',
            'confidence_filtering': 'enabled',  # Only evaluate low-confidence words
            'strict_llm_parsing': 'enabled',   # Strict YES/NO parsing
            'dictionary_filtering': 'enabled',  # New: Dictionary-based common word filtering
            'docker_networking': 'configured',
            'note': f'Only low-confidence words (< {self.confidence_threshold:.0%}) are evaluated. Common English dictionary words and contractions are automatically filtered out using intelligent pattern detection. Musical terms with special characters (-, _, #, +) are preserved throughout the evaluation process. Guitar terms are boosted to 100%, others keep original confidence. LLM receives original words with all special characters intact.'
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
        Only low-confidence words are evaluated. Common English dictionary words are automatically filtered out to prevent false positives.
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