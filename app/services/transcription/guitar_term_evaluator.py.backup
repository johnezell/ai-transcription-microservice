import json
import requests
from typing import Dict, List, Any, Optional, Tuple
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

@dataclass
class MusicalCountingPattern:
    """Represents a detected musical counting pattern"""
    pattern_type: str
    words: List[str]
    start_index: int
    end_index: int
    confidence_boost: float
    description: str

class GuitarTerminologyEvaluator:
    """Service to evaluate and boost confidence for guitar instruction terms and musical counting patterns"""
    
    def __init__(self, 
                 llm_endpoint: str = None,
                 model_name: str = None,
                 confidence_threshold: float = 0.75,  # Only evaluate words below this confidence
                 target_confidence: float = 1.0):    # Set to 100% as requested
        
        # Use environment variables with fallbacks for Docker container networking
        self.llm_endpoint = llm_endpoint or os.getenv('LLM_ENDPOINT', 'http://host.docker.internal:11434/api/generate')
        self.model_name = model_name or os.getenv('LLM_MODEL', 'llama3:latest')
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
        
        # Check if original_confidence already exists from previous processing
        # If so, preserve it; otherwise, use current confidence
        original_confidence = word_data.get('original_confidence', confidence)
        
        segment = WordSegment(word=word, start=start, end=end, confidence=confidence)
        segment.original_confidence = original_confidence
        
        return segment

    def query_local_llm(self, word: str, context: str = "") -> Tuple[bool, bool]:
        """Query local LLM to determine if word is guitar instruction terminology
        
        Returns:
            tuple[bool, bool]: (is_guitar_term, llm_was_used)
        """
        # Use conservative cache key that preserves musical characters
        cache_key = self._normalize_for_cache(word)
        # Use regular normalization for library lookups (removes some punctuation)
        normalized_word = self._normalize_word(word)
        
        if cache_key in self.evaluation_cache:
            logger.debug(f"Cache hit for '{word}' (cache_key: '{cache_key}')")
            return self.evaluation_cache[cache_key], False  # Cache hit, no LLM used
        
        # STEP 1: Check comprehensive guitar library first (confirmed guitar terms)
        if self.guitar_library:
            is_guitar_term = self.guitar_library.is_guitar_term(normalized_word)
            if is_guitar_term:
                logger.debug(f"Guitar library confirmed '{word}' (normalized: '{normalized_word}') as guitar term")
                self.evaluation_cache[cache_key] = True
                return True, False  # Library found it, no LLM used
        
        # STEP 2: Check basic fallback guitar terms
        if normalized_word in self.basic_guitar_terms:
            logger.debug(f"Basic fallback confirmed '{word}' (normalized: '{normalized_word}') as guitar term")
            self.evaluation_cache[cache_key] = True
            return True, False  # Library found it, no LLM used
        
        # STEP 3: Check if it's a common English dictionary word - if yes, don't enhance
        if self._is_common_english_word(word):
            logger.debug(f"Dictionary word '{word}' (cache_key: '{cache_key}') - will not enhance common English word")
            self.evaluation_cache[cache_key] = False
            return False, False  # Dictionary word, no LLM used
        
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
                    return is_guitar_term, True  # LLM was used successfully
                else:
                    logger.warning(f"LLM request failed with status {response.status_code} for word '{word}' - falling back to library-only mode")
                    return False, True  # LLM was attempted but failed
                
            except Exception as e:
                logger.debug(f"LLM query failed for '{word}': {e} - using library-only evaluation")
                return False, True  # LLM was attempted but failed
        else:
            logger.debug(f"LLM disabled - using library-only evaluation for '{word}'")
        
        # Final fallback: conservative approach - don't boost unknown terms
        logger.debug(f"Term '{word}' not found in guitar libraries and LLM unavailable/failed, marking as non-guitar term")
        self.evaluation_cache[cache_key] = False
        return False, False  # No LLM used, library only

    def get_context_window(self, segments: List[WordSegment], index: int, window_size: int = 3) -> str:
        """Get surrounding words for context"""
        start_idx = max(0, index - window_size)
        end_idx = min(len(segments), index + window_size + 1)
        context_words = [seg.word for seg in segments[start_idx:end_idx]]
        return " ".join(context_words)

    def evaluate_and_boost(self, transcription_json: Dict[str, Any]) -> Dict[str, Any]:
        """
        Main method: evaluate guitar terms and musical counting patterns, set their confidence to 100%
        """
        logger.info("Starting guitar terminology and musical counting pattern evaluation...")
        segments = self.extract_words_from_json(transcription_json)
        logger.info(f"Found {len(segments)} word segments for evaluation")
        
        # STEP 1: Detect musical counting patterns first
        logger.info("Detecting musical counting patterns...")
        musical_patterns = self._detect_musical_counting_patterns(segments)
        logger.info(f"Found {len(musical_patterns)} musical counting patterns")
        
        # Apply musical counting pattern boosts
        counting_boosted_count = self._apply_counting_pattern_boosts(segments, musical_patterns)
        
        # STEP 2: Individual guitar term evaluation (for non-pattern words)
        logger.info("Starting individual guitar term evaluation...")
        boosted_count = 0
        unchanged_count = 0
        dictionary_filtered_count = 0
        evaluated_terms = []
        llm_queries_made = 0
        llm_successful_responses = 0
        
        # Track which words were already boosted by counting patterns
        pattern_boosted_indices = set()
        for pattern in musical_patterns:
            for i in range(pattern.start_index, pattern.end_index + 1):
                pattern_boosted_indices.add(i)
        
        for i, segment in enumerate(segments):
            if not segment.word or len(segment.word.strip()) < 2:
                continue
            
            # Skip words that were already boosted by musical counting patterns
            if i in pattern_boosted_indices:
                logger.debug(f"Skipped '{segment.word}': already boosted by musical counting pattern")
                continue
            
            # Original confidence is now preserved in _create_word_segment
            
            # Only evaluate words with confidence below threshold (performance optimization)
            if segment.confidence >= self.confidence_threshold:
                unchanged_count += 1
                logger.debug(f"Skipped '{segment.word}': {segment.original_confidence:.3f} (confidence already high)")
                continue
            
            # Evaluate low-confidence words to see if they're guitar terms
            context = self.get_context_window(segments, i)
            
            is_guitar_term, llm_was_used = self.query_local_llm(segment.word, context)
            
            # Track LLM usage statistics
            if llm_was_used:
                llm_queries_made += 1
                if is_guitar_term:
                    llm_successful_responses += 1
            
            if is_guitar_term:
                # ONLY guitar terms get boosted to 100% confidence
                segment.confidence = self.target_confidence
                boosted_count += 1
                evaluated_terms.append({
                    'word': segment.word,
                    'normalized_word': self._normalize_word(segment.word),
                    'original_confidence': segment.original_confidence,
                    'new_confidence': segment.confidence,
                    'start': segment.start,
                    'end': segment.end,
                    'boost_reason': 'guitar_terminology'
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
        
        # Add counting pattern terms to evaluated_terms for reporting
        for pattern in musical_patterns:
            for i in range(pattern.start_index, pattern.end_index + 1):
                if i < len(segments):
                    segment = segments[i]
                    evaluated_terms.append({
                        'word': segment.word,
                        'normalized_word': self._normalize_word(segment.word),
                        'original_confidence': segment.original_confidence,
                        'new_confidence': segment.confidence,
                        'start': segment.start,
                        'end': segment.end,
                        'boost_reason': 'musical_counting_pattern',
                        'pattern_type': pattern.pattern_type,
                        'pattern_description': pattern.description
                    })
        
        total_boosted = boosted_count + counting_boosted_count
        logger.info(f"Enhanced confidence for {total_boosted} terms total: "
                   f"{boosted_count} guitar terms + {counting_boosted_count} musical counting words, "
                   f"left {unchanged_count} non-musical terms unchanged ({dictionary_filtered_count} were dictionary words)")
        
        # Update the original JSON structure with enhanced confidence scores
        updated_json = self._update_json_with_new_confidence(transcription_json, segments, evaluated_terms)
        
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
        
        # Determine what AI model was actually used
        if llm_queries_made > 0:
            llm_used = f"{self.model_name} + Library"
        elif self.llm_enabled:
            llm_used = "Library Only (no unknown terms)"
        else:
            llm_used = "Library Only (LLM disabled)"

        # Add evaluation metadata with musical counting pattern information
        updated_json['guitar_term_evaluation'] = {
            'evaluator_version': '3.4',  # Update: Added compound musical term detection
            'total_words_evaluated': len(segments),
            'musical_terms_found': boosted_count,
            'musical_counting_words_found': counting_boosted_count,
            'total_enhanced_words': total_boosted,
            'non_musical_terms_unchanged': unchanged_count,
            'dictionary_words_filtered': dictionary_filtered_count,
            'confidence_threshold': self.confidence_threshold,
            'target_confidence': self.target_confidence,
            'evaluation_timestamp': json.dumps(evaluated_terms, default=str),  # For serialization
            'enhanced_terms': evaluated_terms,
            'musical_counting_patterns': [
                {
                    'pattern_type': pattern.pattern_type,
                    'words': pattern.words,
                    'description': pattern.description,
                    'start_index': pattern.start_index,
                    'end_index': pattern.end_index,
                    'confidence_boost': pattern.confidence_boost
                }
                for pattern in musical_patterns
            ],
            'pattern_statistics': {
                'total_patterns_found': len(musical_patterns),
                'pattern_types': list(set(pattern.pattern_type for pattern in musical_patterns)),
                'words_boosted_by_patterns': counting_boosted_count
            },
            'llm_used': llm_used,  # This is what the frontend displays
            'llm_statistics': {
                'queries_made': llm_queries_made,
                'successful_responses': llm_successful_responses,
                'cache_hits': len(self.evaluation_cache)
            },
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
            'dictionary_filtering': 'enabled',  # Dictionary-based common word filtering
            'musical_counting_detection': 'enabled',  # Musical counting pattern detection
            'compound_musical_terms': 'enabled',  # NEW: Compound musical term detection
            'docker_networking': 'configured',
            'note': f'Enhanced {total_boosted} total words: {boosted_count} guitar terms + {counting_boosted_count} musical counting/pattern words from {len(musical_patterns)} detected patterns. Compound musical terms like "4 chord", "minor 7", "flat 5" are detected and boosted as units. Only low-confidence words (< {self.confidence_threshold:.0%}) are evaluated. Common English dictionary words are automatically filtered out. Musical counting patterns like "1, 2, 3, 4, 5" and compound terms are detected and boosted to 100% confidence. Guitar terms with special characters (-, _, #, +) are preserved. LLM made {llm_queries_made} queries with {llm_successful_responses} successful guitar term identifications.'
        }
        
        return updated_json

    def _update_json_with_new_confidence(self, original_json: Dict[str, Any], 
                                       segments: List[WordSegment], 
                                       evaluated_terms: List[Dict[str, Any]]) -> Dict[str, Any]:
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
                        # Determine boost reason from evaluated_terms if available
                        boost_reason = 'musical_terminology'  # default
                        for term in evaluated_terms:
                            if (term.get('word') == seg.word and 
                                term.get('start') == seg.start and 
                                term.get('end') == seg.end):
                                boost_reason = term.get('boost_reason', 'musical_terminology')
                                if boost_reason == 'musical_counting_pattern':
                                    word_data['musical_counting_pattern'] = True
                                    word_data['pattern_type'] = term.get('pattern_type', 'unknown')
                                    word_data['pattern_description'] = term.get('pattern_description', '')
                                break
                        word_data['boost_reason'] = boost_reason
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
                                # Determine boost reason from evaluated_terms if available
                                boost_reason = 'musical_terminology'  # default
                                for term in evaluated_terms:
                                    if (term.get('word') == seg.word and 
                                        term.get('start') == seg.start and 
                                        term.get('end') == seg.end):
                                        boost_reason = term.get('boost_reason', 'musical_terminology')
                                        if boost_reason == 'musical_counting_pattern':
                                            word_data['musical_counting_pattern'] = True
                                            word_data['pattern_type'] = term.get('pattern_type', 'unknown')
                                            word_data['pattern_description'] = term.get('pattern_description', '')
                                        break
                                word_data['boost_reason'] = boost_reason
                                word_data['normalized_form'] = self._normalize_word(seg.word)
                            
                            segment_idx += 1
        
        return updated_json

    def _is_number_word(self, word: str) -> bool:
        """Check if a word represents a number (digit or word form)"""
        if not word:
            return False
        
        word_clean = word.strip().lower()
        
        # Check if it's a digit
        if word_clean.isdigit():
            return True
        
        # Check if it's a number word
        number_words = {
            "one", "two", "three", "four", "five", "six", "seven", "eight", "nine", "ten",
            "eleven", "twelve", "thirteen", "fourteen", "fifteen", "sixteen", 
            "seventeen", "eighteen", "nineteen", "twenty"
        }
        
        return word_clean in number_words

    def _word_to_number(self, word: str) -> Optional[int]:
        """Convert word to its numeric value"""
        if not word:
            return None
        
        word_clean = word.strip().lower()
        
        # Handle digits
        if word_clean.isdigit():
            return int(word_clean)
        
        # Handle number words
        number_mapping = {
            "one": 1, "two": 2, "three": 3, "four": 4, "five": 5,
            "six": 6, "seven": 7, "eight": 8, "nine": 9, "ten": 10,
            "eleven": 11, "twelve": 12, "thirteen": 13, "fourteen": 14, "fifteen": 15,
            "sixteen": 16, "seventeen": 17, "eighteen": 18, "nineteen": 19, "twenty": 20
        }
        
        return number_mapping.get(word_clean)

    def _detect_musical_counting_patterns(self, segments: List[WordSegment]) -> List[MusicalCountingPattern]:
        """Detect musical counting patterns in the word segments (OPTIMIZED)"""
        patterns = []
        processed_indices = set()  # Track processed word indices to avoid overlaps
        
        for i in range(len(segments)):
            # Skip if this word was already processed as part of another pattern
            if i in processed_indices:
                continue
            
            # Try compound musical terms first (most specific)
            compound_pattern = self._check_compound_musical_terms(segments, i)
            if compound_pattern:
                patterns.append(compound_pattern)
                # Mark all words in this pattern as processed
                for idx in range(compound_pattern.start_index, compound_pattern.end_index + 1):
                    processed_indices.add(idx)
                continue
            
            # Try sequential counting patterns (medium specificity)
            pattern = self._check_sequential_pattern(segments, i)
            if pattern:
                patterns.append(pattern)
                # Mark all words in this pattern as processed
                for idx in range(pattern.start_index, pattern.end_index + 1):
                    processed_indices.add(idx)
                continue
            
            # Try other musical instruction patterns (broader)
            musical_pattern = self._check_musical_instruction_patterns(segments, i)
            if musical_pattern:
                patterns.append(musical_pattern)
                # Mark all words in this pattern as processed
                for idx in range(musical_pattern.start_index, musical_pattern.end_index + 1):
                    processed_indices.add(idx)
                continue
        
        return patterns

    def _check_sequential_pattern(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Check for sequential counting pattern starting at given index"""
        if start_index >= len(segments):
            return None
        
        # Must start with a number
        first_word = segments[start_index].word
        if not self._is_number_word(first_word):
            return None
        
        first_num = self._word_to_number(first_word)
        if first_num is None:
            return None
        
        # Look for consecutive numbers
        sequence = [first_word]
        current_index = start_index
        expected_next = first_num + 1
        
        # Look ahead for continuation of the sequence
        for j in range(start_index + 1, min(start_index + 10, len(segments))):  # Look up to 10 words ahead
            current_word = segments[j].word
            
            # Skip common filler words between numbers
            if current_word.lower() in {"and", "then", "now", "ok", "okay", "so", "well", "uh", "um"}:
                continue
            
            # Check if this is the next number in sequence
            if self._is_number_word(current_word):
                current_num = self._word_to_number(current_word)
                if current_num == expected_next:
                    sequence.append(current_word)
                    current_index = j
                    expected_next += 1
                    continue
                elif current_num is not None and current_num > expected_next:
                    # Skip in sequence, still musical (like 1, 3, 5)
                    sequence.append(current_word)
                    current_index = j
                    expected_next = current_num + 1
                    continue
            
            # If we hit a non-number that's not a filler, stop looking
            break
        
        # Determine if this is a musical counting pattern
        if len(sequence) >= 3:  # At least 3 numbers in sequence
            # Common musical patterns
            if len(sequence) >= 4 and first_num == 1:
                return MusicalCountingPattern(
                    pattern_type="musical_count_in",
                    words=sequence,
                    start_index=start_index,
                    end_index=current_index,
                    confidence_boost=1.0,  # Boost to 100%
                    description=f"Musical count-in pattern: {', '.join(sequence)}"
                )
            elif len(sequence) >= 3:
                return MusicalCountingPattern(
                    pattern_type="sequential_counting",
                    words=sequence,
                    start_index=start_index,
                    end_index=current_index,
                    confidence_boost=1.0,  # Boost to 100%
                    description=f"Sequential counting pattern: {', '.join(sequence)}"
                )
        
        # Check for common musical timing patterns
        if len(sequence) == 4 and first_num == 1:
            # "1, 2, 3, 4" is a very common musical count-in
            return MusicalCountingPattern(
                pattern_type="four_count",
                words=sequence,
                start_index=start_index,
                end_index=current_index,
                confidence_boost=1.0,
                description="Four-count musical timing pattern"
            )
        
        # Check for specific musical patterns even with 2 numbers
        if len(sequence) == 2:
            if first_num == 1 and self._word_to_number(sequence[1]) == 2:
                # Check if followed by musical context
                context = self._get_context_after_pattern(segments, current_index)
                if any(word in context.lower() for word in ["and", "a", "ready", "go", "play", "start", "begin"]):
                    return MusicalCountingPattern(
                        pattern_type="count_start",
                        words=sequence,
                        start_index=start_index,
                        end_index=current_index,
                        confidence_boost=1.0,
                        description="Musical count start pattern"
                    )
        
        return None

    def _get_context_after_pattern(self, segments: List[WordSegment], end_index: int, window_size: int = 5) -> str:
        """Get context words after a counting pattern"""
        start_idx = end_index + 1
        end_idx = min(len(segments), start_idx + window_size)
        context_words = [seg.word for seg in segments[start_idx:end_idx]]
        return " ".join(context_words)

    def _apply_counting_pattern_boosts(self, segments: List[WordSegment], patterns: List[MusicalCountingPattern]) -> int:
        """Apply confidence boosts to detected musical counting patterns"""
        boosted_count = 0
        
        for pattern in patterns:
            logger.info(f"Applying musical counting pattern boost: {pattern.description}")
            
            # Boost confidence for all words in the pattern
            for i in range(pattern.start_index, pattern.end_index + 1):
                if i < len(segments):
                    segment = segments[i]
                    
                    # Only boost if current confidence is below threshold
                    if segment.confidence < self.confidence_threshold:
                        # Store original confidence if not already stored
                        if segment.original_confidence is None:
                            segment.original_confidence = segment.confidence
                        
                        # Apply boost
                        segment.confidence = pattern.confidence_boost
                        boosted_count += 1
                        
                        logger.debug(f"Boosted musical counting word '{segment.word}': "
                                   f"{segment.original_confidence:.3f} -> {segment.confidence:.3f} "
                                   f"(pattern: {pattern.pattern_type})")
        
        return boosted_count

    def _check_musical_instruction_patterns(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Check for various musical instruction patterns beyond counting (OPTIMIZED)"""
        if start_index >= len(segments):
            return None
        
        first_word_lower = segments[start_index].word.lower()
        
        # OPTIMIZATION: Quick rejection for non-musical starting words
        musical_starters = {
            # Rhythm syllables
            'da', 'dum', 'ta', 'ka', 'boom', 'chick', 'tick', 'tock', 'doo', 'dah', 'bah', 'pah', 'tsk', 'tik', 'tak', 'tuk',
            # Strumming terms
            'down', 'up', 'strum', 'pick', 'hit', 'miss', 'rest', 'mute', 'downstroke', 'upstroke', 'stroke', 'pluck', 'attack',
            # Notes (handled in note sequence check)
            'a', 'b', 'c', 'd', 'e', 'f', 'g', 'do', 're', 'mi', 'fa', 'sol', 'la', 'ti', 'si',
            # Fingerpicking
            'thumb', 'index', 'middle', 'ring', 'pinky', 't', 'i', 'm', 'r', 'p',
            # Timing
            'click', 'beep', 'boop', 'pop', 'tap', 'clap', 'snap', 'beat',
            # Effects
            'wah', 'ring', 'buzz', 'twang', 'zing', 'ping', 'whoosh', 'screech', 'squeal', 'howl', 'whine', 'boom', 'crash', 'bang', 'thud', 'thump'
        }
        
        if first_word_lower not in musical_starters:
            return None
        
        # Check most common patterns first for efficiency
        
        # Check for rhythm vocalization patterns (most common)
        if first_word_lower in {'da', 'dum', 'ta', 'ka', 'boom', 'chick', 'tick', 'tock', 'doo', 'dah', 'bah', 'pah', 'tsk', 'tik', 'tak', 'tuk'}:
            rhythm_pattern = self._check_rhythm_vocalization_pattern(segments, start_index)
            if rhythm_pattern:
                return rhythm_pattern
        
        # Check for strumming patterns
        if first_word_lower in {'down', 'up', 'strum', 'pick', 'hit', 'miss', 'rest', 'mute', 'downstroke', 'upstroke', 'stroke', 'pluck', 'attack'}:
            strumming_pattern = self._check_strumming_pattern(segments, start_index)
            if strumming_pattern:
                return strumming_pattern
        
        # Check for note/chord sequences
        if self._is_note_or_chord_name(segments[start_index].word):
            note_sequence_pattern = self._check_note_sequence_pattern(segments, start_index)
            if note_sequence_pattern:
                return note_sequence_pattern
        
        # Check for fingerpicking patterns
        if first_word_lower in {'thumb', 'index', 'middle', 'ring', 'pinky', 't', 'i', 'm', 'r', 'p'}:
            fingerpicking_pattern = self._check_fingerpicking_pattern(segments, start_index)
            if fingerpicking_pattern:
                return fingerpicking_pattern
        
        # Check for timing/metronome patterns
        if first_word_lower in {'tick', 'tock', 'click', 'beep', 'boop', 'pop', 'tap', 'clap', 'snap', 'beat'}:
            timing_pattern = self._check_timing_pattern(segments, start_index)
            if timing_pattern:
                return timing_pattern
        
        # Check for guitar effect sound patterns
        if first_word_lower in {'wah', 'ring', 'buzz', 'twang', 'zing', 'ping', 'whoosh', 'screech', 'squeal', 'howl', 'whine', 'boom', 'crash', 'bang', 'thud', 'thump'}:
            effect_pattern = self._check_effect_sound_pattern(segments, start_index)
            if effect_pattern:
                return effect_pattern
        
        return None

    def _check_rhythm_vocalization_pattern(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect rhythm vocalization patterns like 'da-da-da-dum' or 'boom-chick-boom-chick'"""
        if start_index >= len(segments):
            return None
        
        first_word = segments[start_index].word.lower()
        
        # Common rhythm syllables
        rhythm_syllables = {
            'da', 'dum', 'ta', 'ka', 'boom', 'chick', 'tick', 'tock', 
            'doo', 'dah', 'bah', 'pah', 'tsk', 'tik', 'tak', 'tuk'
        }
        
        # Check if first word is a rhythm syllable
        clean_first = first_word.strip('-').lower()
        if clean_first not in rhythm_syllables:
            return None
        
        # Look for repetitive rhythm pattern
        sequence = [first_word]
        current_index = start_index
        
        for j in range(start_index + 1, min(start_index + 8, len(segments))):
            current_word = segments[j].word.lower()
            clean_word = current_word.strip('-').lower()
            
            # Continue if it's a rhythm syllable
            if clean_word in rhythm_syllables:
                sequence.append(segments[j].word)
                current_index = j
                continue
            # Allow short connector words
            elif current_word in {'and', 'a'}:
                continue
            else:
                break
        
        # Need at least 3 rhythm syllables for a pattern
        if len(sequence) >= 3:
            return MusicalCountingPattern(
                pattern_type="rhythm_vocalization",
                words=sequence,
                start_index=start_index,
                end_index=current_index,
                confidence_boost=1.0,
                description=f"Rhythm vocalization pattern: {'-'.join(sequence)}"
            )
        
        return None

    def _check_strumming_pattern(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect strumming pattern descriptions like 'down-up-down-up'"""
        if start_index >= len(segments):
            return None
        
        first_word = segments[start_index].word.lower()
        
        # Strumming direction terms
        strumming_terms = {
            'down', 'up', 'strum', 'pick', 'hit', 'miss', 'rest', 'mute',
            'downstroke', 'upstroke', 'stroke', 'pluck', 'attack'
        }
        
        if first_word not in strumming_terms:
            return None
        
        # Look for repetitive strumming pattern
        sequence = [first_word]
        current_index = start_index
        
        for j in range(start_index + 1, min(start_index + 8, len(segments))):
            current_word = segments[j].word.lower()
            
            if current_word in strumming_terms:
                sequence.append(segments[j].word)
                current_index = j
                continue
            elif current_word in {'and', 'then'}:
                continue
            else:
                break
        
        # Need at least 3 strumming terms for a pattern
        if len(sequence) >= 3:
            return MusicalCountingPattern(
                pattern_type="strumming_pattern",
                words=sequence,
                start_index=start_index,
                end_index=current_index,
                confidence_boost=1.0,
                description=f"Strumming pattern: {'-'.join(sequence)}"
            )
        
        return None

    def _check_note_sequence_pattern(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect note/chord sequences like 'C-G-Am-F' or 'E-A-D-G-B-E'"""
        if start_index >= len(segments):
            return None
        
        first_word = segments[start_index].word
        
        # Check if it's a note or chord name
        if not self._is_note_or_chord_name(first_word):
            return None
        
        # Look for sequence of notes/chords
        sequence = [first_word]
        current_index = start_index
        
        for j in range(start_index + 1, min(start_index + 8, len(segments))):
            current_word = segments[j].word
            
            if self._is_note_or_chord_name(current_word):
                sequence.append(current_word)
                current_index = j
                continue
            elif current_word.lower() in {'to', 'and', 'then'}:
                continue
            else:
                break
        
        # Need at least 3 notes/chords for a sequence
        if len(sequence) >= 3:
            return MusicalCountingPattern(
                pattern_type="note_sequence",
                words=sequence,
                start_index=start_index,
                end_index=current_index,
                confidence_boost=1.0,
                description=f"Note/chord sequence: {'-'.join(sequence)}"
            )
        
        return None

    def _is_note_or_chord_name(self, word: str) -> bool:
        """Check if a word is a note or chord name"""
        if not word:
            return False
        
        word_clean = word.strip().upper()
        
        # Basic note names
        basic_notes = {'A', 'B', 'C', 'D', 'E', 'F', 'G'}
        
        # Check for single note
        if word_clean in basic_notes:
            return True
        
        # Check for note with accidental (A#, Bb, C#, etc.)
        if len(word_clean) == 2 and word_clean[0] in basic_notes and word_clean[1] in {'#', 'B', '♯', '♭'}:
            return True
        
        # Common chord suffixes
        chord_patterns = [
            'M', 'MIN', 'MAJ', 'DIM', 'AUG', 'SUS', 'ADD',
            '7', '9', '11', '13', 'M7', 'MAJ7', 'MIN7', 'DIM7'
        ]
        
        # Check for chord names (C, Cm, CM7, Am, etc.)
        for note in basic_notes:
            for pattern in ['', 'M', 'm'] + chord_patterns:
                if word_clean == note + pattern:
                    return True
            
            # With accidentals
            for accidental in ['#', 'b', '♯', '♭']:
                for pattern in ['', 'M', 'm'] + chord_patterns:
                    if word_clean == note + accidental + pattern:
                        return True
        
        # Solfege syllables
        solfege = {'DO', 'RE', 'MI', 'FA', 'SOL', 'LA', 'TI', 'SI'}
        if word_clean in solfege:
            return True
        
        return False

    def _check_fingerpicking_pattern(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect fingerpicking patterns like 'thumb-index-middle-ring' or 'T-I-M-R'"""
        if start_index >= len(segments):
            return None
        
        first_word = segments[start_index].word.lower()
        
        # Fingerpicking terms
        finger_terms = {
            'thumb', 'index', 'middle', 'ring', 'pinky',
            't', 'i', 'm', 'r', 'p', 'a',  # Classical notation
            'pick', 'rest', 'pluck', 'strike'
        }
        
        if first_word not in finger_terms:
            return None
        
        # Look for fingerpicking sequence
        sequence = [first_word]
        current_index = start_index
        
        for j in range(start_index + 1, min(start_index + 6, len(segments))):
            current_word = segments[j].word.lower()
            
            if current_word in finger_terms:
                sequence.append(segments[j].word)
                current_index = j
                continue
            elif current_word in {'and', 'then'}:
                continue
            else:
                break
        
        # Need at least 3 finger terms for a pattern
        if len(sequence) >= 3:
            return MusicalCountingPattern(
                pattern_type="fingerpicking_pattern",
                words=sequence,
                start_index=start_index,
                end_index=current_index,
                confidence_boost=1.0,
                description=f"Fingerpicking pattern: {'-'.join(sequence)}"
            )
        
        return None

    def _check_timing_pattern(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect timing/metronome patterns like 'tick-tick-tick-tick'"""
        if start_index >= len(segments):
            return None
        
        first_word = segments[start_index].word.lower()
        
        # Timing/metronome sounds
        timing_sounds = {
            'tick', 'tock', 'click', 'beep', 'boop', 'pop',
            'tap', 'clap', 'snap', 'beat'
        }
        
        if first_word not in timing_sounds:
            return None
        
        # Look for repetitive timing pattern
        sequence = [first_word]
        current_index = start_index
        
        for j in range(start_index + 1, min(start_index + 8, len(segments))):
            current_word = segments[j].word.lower()
            
            if current_word in timing_sounds:
                sequence.append(segments[j].word)
                current_index = j
                continue
            else:
                break
        
        # Need at least 3 timing sounds for a pattern
        if len(sequence) >= 3:
            return MusicalCountingPattern(
                pattern_type="timing_pattern",
                words=sequence,
                start_index=start_index,
                end_index=current_index,
                confidence_boost=1.0,
                description=f"Timing/metronome pattern: {'-'.join(sequence)}"
            )
        
        return None

    def _check_effect_sound_pattern(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect guitar effect sound patterns like 'wah-wah-wah'"""
        if start_index >= len(segments):
            return None
        
        first_word = segments[start_index].word.lower()
        
        # Guitar effect sounds and onomatopoeia
        effect_sounds = {
            'wah', 'ring', 'buzz', 'twang', 'zing', 'ping',
            'whoosh', 'screech', 'squeal', 'howl', 'whine',
            'boom', 'crash', 'bang', 'thud', 'thump'
        }
        
        if first_word not in effect_sounds:
            return None
        
        # Look for repetitive effect pattern
        sequence = [first_word]
        current_index = start_index
        
        for j in range(start_index + 1, min(start_index + 6, len(segments))):
            current_word = segments[j].word.lower()
            
            if current_word in effect_sounds:
                sequence.append(segments[j].word)
                current_index = j
                continue
            else:
                break
        
        # Need at least 2 effect sounds for a pattern (effects often repeat)
        if len(sequence) >= 2:
            return MusicalCountingPattern(
                pattern_type="effect_sound_pattern",
                words=sequence,
                start_index=start_index,
                end_index=current_index,
                confidence_boost=1.0,
                description=f"Guitar effect sound pattern: {'-'.join(sequence)}"
            )
        
        return None

    def _check_compound_musical_terms(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect compound musical terms like '4 chord', 'minor 7', 'flat 5', etc. (OPTIMIZED)"""
        if start_index >= len(segments):
            return None
        
        # Quick early exit if we don't have enough words for any compound term
        if start_index + 1 >= len(segments):
            return None
        
        first_word = segments[start_index].word
        first_word_lower = first_word.lower()
        
        # OPTIMIZATION: Quick rejection for words that can't start compound terms
        compound_starters = {
            # Roman numeral numbers
            '1', '2', '3', '4', '5', '6', '7', 'i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii',
            # Chord qualities
            'major', 'minor', 'dominant', 'diminished', 'augmented', 'suspended', 'sus', 'add', 'maj', 'min', 'dim', 'aug',
            # Accidentals
            'flat', 'sharp', 'natural'
        }
        
        if first_word_lower not in compound_starters and first_word not in compound_starters:
            return None
        
        # Check for Roman numeral chord references (number + chord) - MOST COMMON
        roman_chord_pattern = self._check_roman_numeral_chord(segments, start_index)
        if roman_chord_pattern:
            return roman_chord_pattern
        
        # Check for extended chord notation (quality + number [+ chord])
        extended_chord_pattern = self._check_extended_chord_notation(segments, start_index)
        if extended_chord_pattern:
            return extended_chord_pattern
        
        # Check for altered chord notation (accidental + number [+ chord])
        altered_chord_pattern = self._check_altered_chord_notation(segments, start_index)
        if altered_chord_pattern:
            return altered_chord_pattern
        
        return None

    def _check_roman_numeral_chord(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect Roman numeral chord references like '4 chord', '5 chord', '1 chord'"""
        if start_index + 1 >= len(segments):
            return None
        
        first_word = segments[start_index].word
        second_word = segments[start_index + 1].word.lower()
        
        # Check for number + "chord" pattern
        if self._is_chord_number(first_word) and second_word == "chord":
            return MusicalCountingPattern(
                pattern_type="roman_numeral_chord",
                words=[first_word, segments[start_index + 1].word],
                start_index=start_index,
                end_index=start_index + 1,
                confidence_boost=1.0,
                description=f"Roman numeral chord: {first_word} chord"
            )
        
        return None

    def _check_extended_chord_notation(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect extended chord notation like 'minor 7', 'major 9', 'dominant 11'"""
        if start_index + 1 >= len(segments):
            return None
        
        first_word = segments[start_index].word.lower()
        second_word = segments[start_index + 1].word
        
        # Chord quality words
        chord_qualities = {
            'major', 'minor', 'dominant', 'diminished', 'augmented', 
            'suspended', 'sus', 'add', 'maj', 'min', 'dim', 'aug'
        }
        
        # Check for quality + number pattern
        if first_word in chord_qualities and self._is_chord_extension_number(second_word):
            sequence = [segments[start_index].word, second_word]
            end_index = start_index + 1
            
            # Check if followed by "chord"
            if start_index + 2 < len(segments) and segments[start_index + 2].word.lower() == "chord":
                sequence.append(segments[start_index + 2].word)
                end_index = start_index + 2
            
            return MusicalCountingPattern(
                pattern_type="extended_chord_notation",
                words=sequence,
                start_index=start_index,
                end_index=end_index,
                confidence_boost=1.0,
                description=f"Extended chord notation: {' '.join(sequence)}"
            )
        
        return None

    def _check_altered_chord_notation(self, segments: List[WordSegment], start_index: int) -> Optional[MusicalCountingPattern]:
        """Detect altered chord notation like 'flat 7', 'sharp 11', 'flat 5'"""
        if start_index + 1 >= len(segments):
            return None
        
        first_word = segments[start_index].word.lower()
        second_word = segments[start_index + 1].word
        
        # Accidental words
        accidentals = {'flat', 'sharp', 'natural'}
        
        # Check for accidental + number pattern
        if first_word in accidentals and self._is_chord_extension_number(second_word):
            sequence = [segments[start_index].word, second_word]
            end_index = start_index + 1
            
            # Check if followed by "chord"
            if start_index + 2 < len(segments) and segments[start_index + 2].word.lower() == "chord":
                sequence.append(segments[start_index + 2].word)
                end_index = start_index + 2
            
            return MusicalCountingPattern(
                pattern_type="altered_chord_notation",
                words=sequence,
                start_index=start_index,
                end_index=end_index,
                confidence_boost=1.0,
                description=f"Altered chord notation: {' '.join(sequence)}"
            )
        
        return None

    def _is_chord_number(self, word: str) -> bool:
        """Check if word is a number used in Roman numeral chord analysis"""
        if not word:
            return False
        
        # Common Roman numeral chord numbers (both digits and roman)
        chord_numbers = {
            '1', '2', '3', '4', '5', '6', '7',
            'i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii',
            'I', 'II', 'III', 'IV', 'V', 'VI', 'VII'
        }
        
        return word.strip().lower() in [n.lower() for n in chord_numbers] or word.strip() in chord_numbers

    def _is_chord_extension_number(self, word: str) -> bool:
        """Check if word is a number used in chord extensions"""
        if not word:
            return False
        
        # Common chord extension numbers
        extension_numbers = {
            '2', '4', '5', '6', '7', '9', '11', '13',
            'add2', 'add4', 'add6', 'add9', 'add11', 'add13'
        }
        
        word_clean = word.strip().lower()
        return word_clean in extension_numbers or word.strip() in extension_numbers

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