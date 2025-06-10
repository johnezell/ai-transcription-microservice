"""
Comprehensive Guitar Terminology Library

This module contains an extensive offline database of guitar instruction terminology
organized by categories. Used as a fallback when LLM evaluation is unavailable
and for fast lookup of common terms.
"""

from typing import Dict, List, Set, Optional
from dataclasses import dataclass, field
from enum import Enum

class TermCategory(Enum):
    """Categories for guitar terminology"""
    GUITAR_PARTS = "guitar_parts"
    PLAYING_TECHNIQUES = "playing_techniques"
    MUSICAL_THEORY = "musical_theory"
    CHORDS = "chords"
    SCALES_MODES = "scales_modes"
    EFFECTS_EQUIPMENT = "effects_equipment"
    GUITAR_TYPES = "guitar_types"
    NOTATION_TABLATURE = "notation_tablature"
    MAINTENANCE_SETUP = "maintenance_setup"
    GENRES_STYLES = "genres_styles"
    COMMON_VARIATIONS = "common_variations"
    INSTRUCTION_TERMS = "instruction_terms"

@dataclass
class GuitarTerm:
    """Represents a guitar terminology entry"""
    term: str
    category: TermCategory
    confidence: float = 1.0
    aliases: List[str] = field(default_factory=list)
    description: str = ""

class GuitarTermsLibrary:
    """Comprehensive offline library of guitar terminology"""
    
    def __init__(self):
        self.terms_db = self._build_terms_database()
        self.lookup_set = self._build_lookup_set()
        
    def _build_terms_database(self) -> Dict[str, GuitarTerm]:
        """Build the comprehensive guitar terms database"""
        
        terms = {}
        
        # GUITAR PARTS & HARDWARE - Core physical components
        guitar_parts = [
            GuitarTerm("fret", TermCategory.GUITAR_PARTS, 1.0, ["frets", "fretting"], "Metal strips on neck"),
            GuitarTerm("fretboard", TermCategory.GUITAR_PARTS, 1.0, ["fingerboard", "neck"], "Playing surface"),
            GuitarTerm("headstock", TermCategory.GUITAR_PARTS, 1.0, ["head"], "Top of neck"),
            GuitarTerm("tuning pegs", TermCategory.GUITAR_PARTS, 1.0, ["tuners", "machine heads"], "Tuning hardware"),
            GuitarTerm("nut", TermCategory.GUITAR_PARTS, 1.0, [], "String guide at headstock"),
            GuitarTerm("bridge", TermCategory.GUITAR_PARTS, 1.0, [], "String anchor point"),
            GuitarTerm("saddle", TermCategory.GUITAR_PARTS, 1.0, [], "Bridge string contact point"),
            GuitarTerm("soundhole", TermCategory.GUITAR_PARTS, 1.0, ["sound hole"], "Acoustic guitar opening"),
            GuitarTerm("pickup", TermCategory.GUITAR_PARTS, 1.0, ["pickups"], "Electronic transducer"),
            GuitarTerm("humbucker", TermCategory.GUITAR_PARTS, 1.0, ["humbuckers"], "Dual-coil pickup"),
            GuitarTerm("single coil", TermCategory.GUITAR_PARTS, 1.0, ["single-coil"], "Single coil pickup"),
            GuitarTerm("volume knob", TermCategory.GUITAR_PARTS, 1.0, ["volume control"], "Volume adjustment"),
            GuitarTerm("tone knob", TermCategory.GUITAR_PARTS, 1.0, ["tone control"], "Tone adjustment"),
            GuitarTerm("pickup selector", TermCategory.GUITAR_PARTS, 1.0, ["pickup switch"], "Pickup selection"),
            GuitarTerm("tremolo", TermCategory.GUITAR_PARTS, 1.0, ["trem", "vibrato"], "Vibrato system"),
            GuitarTerm("whammy bar", TermCategory.GUITAR_PARTS, 1.0, ["tremolo arm"], "Tremolo lever"),
        ]
        
        # STRINGS - Specific string terminology
        string_terms = [
            GuitarTerm("strings", TermCategory.GUITAR_PARTS, 1.0, ["string"], "Guitar strings"),
            GuitarTerm("high e string", TermCategory.GUITAR_PARTS, 1.0, ["first string"], "Highest string"),
            GuitarTerm("b string", TermCategory.GUITAR_PARTS, 1.0, ["second string"], "B string"),
            GuitarTerm("g string", TermCategory.GUITAR_PARTS, 1.0, ["third string"], "G string"),
            GuitarTerm("d string", TermCategory.GUITAR_PARTS, 1.0, ["fourth string"], "D string"),
            GuitarTerm("a string", TermCategory.GUITAR_PARTS, 1.0, ["fifth string"], "A string"),
            GuitarTerm("low e string", TermCategory.GUITAR_PARTS, 1.0, ["sixth string"], "Lowest string"),
        ]
        
        # PLAYING TECHNIQUES - How to play the guitar
        playing_techniques = [
            GuitarTerm("strumming", TermCategory.PLAYING_TECHNIQUES, 1.0, ["strum"], "Brushing strings"),
            GuitarTerm("picking", TermCategory.PLAYING_TECHNIQUES, 1.0, ["pick"], "Plucking strings"),
            GuitarTerm("fingerpicking", TermCategory.PLAYING_TECHNIQUES, 1.0, ["fingerstyle"], "Finger technique"),
            GuitarTerm("flatpicking", TermCategory.PLAYING_TECHNIQUES, 1.0, ["flat picking"], "Pick technique"),
            GuitarTerm("alternate picking", TermCategory.PLAYING_TECHNIQUES, 1.0, ["alternating"], "Up-down picking"),
            GuitarTerm("downstroke", TermCategory.PLAYING_TECHNIQUES, 1.0, ["down stroke"], "Downward pick"),
            GuitarTerm("upstroke", TermCategory.PLAYING_TECHNIQUES, 1.0, ["up stroke"], "Upward pick"),
            GuitarTerm("hammer-on", TermCategory.PLAYING_TECHNIQUES, 1.0, ["hammer on"], "Fretting technique"),
            GuitarTerm("pull-off", TermCategory.PLAYING_TECHNIQUES, 1.0, ["pull off"], "Release technique"),
            GuitarTerm("slide", TermCategory.PLAYING_TECHNIQUES, 1.0, ["sliding"], "Sliding between frets"),
            GuitarTerm("bend", TermCategory.PLAYING_TECHNIQUES, 1.0, ["bending"], "String bending"),
            GuitarTerm("vibrato", TermCategory.PLAYING_TECHNIQUES, 1.0, [], "Pitch modulation"),
            GuitarTerm("palm muting", TermCategory.PLAYING_TECHNIQUES, 1.0, ["palm mute"], "Palm dampening"),
            GuitarTerm("harmonics", TermCategory.PLAYING_TECHNIQUES, 1.0, ["harmonic"], "Overtone technique"),
            GuitarTerm("tapping", TermCategory.PLAYING_TECHNIQUES, 1.0, ["tap"], "Finger tapping"),
        ]
        
        # CHORDS - Chord types and theory
        chord_terms = [
            GuitarTerm("chord", TermCategory.CHORDS, 1.0, ["chords"], "Multiple notes together"),
            GuitarTerm("major", TermCategory.CHORDS, 1.0, ["maj"], "Major chord quality"),
            GuitarTerm("minor", TermCategory.CHORDS, 1.0, ["min"], "Minor chord quality"),
            GuitarTerm("seventh", TermCategory.CHORDS, 1.0, ["7th"], "Seventh chord"),
            GuitarTerm("major seventh", TermCategory.CHORDS, 1.0, ["maj7"], "Major seventh"),
            GuitarTerm("minor seventh", TermCategory.CHORDS, 1.0, ["min7"], "Minor seventh"),
            GuitarTerm("diminished", TermCategory.CHORDS, 1.0, ["dim"], "Diminished chord"),
            GuitarTerm("augmented", TermCategory.CHORDS, 1.0, ["aug"], "Augmented chord"),
            GuitarTerm("suspended", TermCategory.CHORDS, 1.0, ["sus"], "Suspended chord"),
            GuitarTerm("sus2", TermCategory.CHORDS, 1.0, [], "Suspended second"),
            GuitarTerm("sus4", TermCategory.CHORDS, 1.0, [], "Suspended fourth"),
            GuitarTerm("add9", TermCategory.CHORDS, 1.0, ["add 9"], "Added ninth"),
            GuitarTerm("ninth", TermCategory.CHORDS, 1.0, ["9th"], "Ninth chord"),
            GuitarTerm("eleventh", TermCategory.CHORDS, 1.0, ["11th"], "Eleventh chord"),
            GuitarTerm("thirteenth", TermCategory.CHORDS, 1.0, ["13th"], "Thirteenth chord"),
            GuitarTerm("power chord", TermCategory.CHORDS, 1.0, [], "Root and fifth"),
            GuitarTerm("barre chord", TermCategory.CHORDS, 1.0, ["bar chord"], "Barred fingering"),
            GuitarTerm("open chord", TermCategory.CHORDS, 1.0, [], "Uses open strings"),
            GuitarTerm("slash chord", TermCategory.CHORDS, 1.0, [], "Chord with bass note"),
            GuitarTerm("triad", TermCategory.CHORDS, 1.0, [], "Three-note chord"),
        ]
        
        # SCALES AND MODES
        scales_modes = [
            GuitarTerm("scale", TermCategory.SCALES_MODES, 1.0, ["scales"], "Note sequence"),
            GuitarTerm("major scale", TermCategory.SCALES_MODES, 1.0, ["ionian"], "Major scale"),
            GuitarTerm("minor scale", TermCategory.SCALES_MODES, 1.0, ["natural minor"], "Minor scale"),
            GuitarTerm("pentatonic", TermCategory.SCALES_MODES, 1.0, [], "Five-note scale"),
            GuitarTerm("blues scale", TermCategory.SCALES_MODES, 1.0, [], "Blues scale"),
            GuitarTerm("chromatic", TermCategory.SCALES_MODES, 1.0, [], "All twelve notes"),
            GuitarTerm("dorian", TermCategory.SCALES_MODES, 1.0, [], "Second mode"),
            GuitarTerm("mixolydian", TermCategory.SCALES_MODES, 1.0, [], "Fifth mode"),
            GuitarTerm("ionian", TermCategory.SCALES_MODES, 1.0, [], "First mode"),
            GuitarTerm("aeolian", TermCategory.SCALES_MODES, 1.0, [], "Sixth mode"),
        ]
        
        # MUSICAL THEORY - General music theory applied to guitar
        musical_theory = [
            GuitarTerm("sharp", TermCategory.MUSICAL_THEORY, 1.0, ["#"], "Raised semitone"),
            GuitarTerm("flat", TermCategory.MUSICAL_THEORY, 1.0, ["b"], "Lowered semitone"),
            GuitarTerm("natural", TermCategory.MUSICAL_THEORY, 1.0, [], "Cancels accidental"),
            GuitarTerm("progression", TermCategory.MUSICAL_THEORY, 1.0, [], "Chord sequence"),
            GuitarTerm("arpeggio", TermCategory.MUSICAL_THEORY, 1.0, [], "Broken chord"),
            GuitarTerm("interval", TermCategory.MUSICAL_THEORY, 1.0, [], "Note distance"),
            GuitarTerm("octave", TermCategory.MUSICAL_THEORY, 1.0, [], "Eight-note span"),
            GuitarTerm("capo", TermCategory.MUSICAL_THEORY, 1.0, [], "Moveable nut"),
            GuitarTerm("transpose", TermCategory.MUSICAL_THEORY, 1.0, [], "Key change"),
            GuitarTerm("key", TermCategory.MUSICAL_THEORY, 1.0, [], "Tonal center"),
        ]
        
        # EFFECTS AND EQUIPMENT
        effects_equipment = [
            GuitarTerm("amplifier", TermCategory.EFFECTS_EQUIPMENT, 1.0, ["amp"], "Sound amplifier"),
            GuitarTerm("distortion", TermCategory.EFFECTS_EQUIPMENT, 1.0, [], "Overdrive effect"),
            GuitarTerm("overdrive", TermCategory.EFFECTS_EQUIPMENT, 1.0, [], "Mild distortion"),
            GuitarTerm("reverb", TermCategory.EFFECTS_EQUIPMENT, 1.0, [], "Ambience effect"),
            GuitarTerm("delay", TermCategory.EFFECTS_EQUIPMENT, 1.0, ["echo"], "Time effect"),
            GuitarTerm("chorus", TermCategory.EFFECTS_EQUIPMENT, 1.0, [], "Modulation effect"),
            GuitarTerm("pedal", TermCategory.EFFECTS_EQUIPMENT, 1.0, ["effect pedal"], "Effects unit"),
            GuitarTerm("cabinet", TermCategory.EFFECTS_EQUIPMENT, 1.0, ["cab"], "Speaker enclosure"),
        ]
        
        # GUITAR TYPES
        guitar_types = [
            GuitarTerm("acoustic", TermCategory.GUITAR_TYPES, 1.0, [], "Acoustic guitar"),
            GuitarTerm("electric", TermCategory.GUITAR_TYPES, 1.0, [], "Electric guitar"), 
            GuitarTerm("classical", TermCategory.GUITAR_TYPES, 1.0, [], "Classical guitar"),
            GuitarTerm("bass", TermCategory.GUITAR_TYPES, 1.0, [], "Bass guitar"),
            GuitarTerm("twelve string", TermCategory.GUITAR_TYPES, 1.0, ["12 string"], "12-string guitar"),
        ]
        
        # TABLATURE AND NOTATION
        notation_tab = [
            GuitarTerm("tablature", TermCategory.NOTATION_TABLATURE, 1.0, ["tab"], "Guitar notation"),
            GuitarTerm("tab", TermCategory.NOTATION_TABLATURE, 1.0, [], "Short for tablature"),
            GuitarTerm("fingering", TermCategory.NOTATION_TABLATURE, 1.0, [], "Finger placement"),
            GuitarTerm("position", TermCategory.NOTATION_TABLATURE, 1.0, [], "Neck position"),
            GuitarTerm("open", TermCategory.NOTATION_TABLATURE, 1.0, [], "Unfretted string"),
            GuitarTerm("mute", TermCategory.NOTATION_TABLATURE, 1.0, ["muted"], "Silenced note"),
        ]
        
        # MAINTENANCE AND SETUP
        maintenance_setup = [
            GuitarTerm("setup", TermCategory.MAINTENANCE_SETUP, 1.0, [], "Guitar adjustment"),
            GuitarTerm("action", TermCategory.MAINTENANCE_SETUP, 1.0, [], "String height"),
            GuitarTerm("intonation", TermCategory.MAINTENANCE_SETUP, 1.0, [], "Pitch accuracy"),
            GuitarTerm("tuning", TermCategory.MAINTENANCE_SETUP, 1.0, ["tune"], "Pitch adjustment"),
            GuitarTerm("string gauge", TermCategory.MAINTENANCE_SETUP, 1.0, [], "String thickness"),
        ]
        
        # INSTRUCTION TERMS
        instruction_terms = [
            GuitarTerm("practice", TermCategory.INSTRUCTION_TERMS, 1.0, [], "Learning activity"),
            GuitarTerm("exercise", TermCategory.INSTRUCTION_TERMS, 1.0, [], "Practice routine"),
            GuitarTerm("lesson", TermCategory.INSTRUCTION_TERMS, 1.0, [], "Instruction session"),
            GuitarTerm("technique", TermCategory.INSTRUCTION_TERMS, 1.0, [], "Playing method"),
            GuitarTerm("tempo", TermCategory.INSTRUCTION_TERMS, 1.0, [], "Music speed"),
            GuitarTerm("rhythm", TermCategory.INSTRUCTION_TERMS, 1.0, [], "Time pattern"),
            GuitarTerm("timing", TermCategory.INSTRUCTION_TERMS, 1.0, [], "Rhythmic accuracy"),
        ]
        
        # COMMON VARIATIONS AND SLANG
        common_variations = [
            GuitarTerm("lick", TermCategory.COMMON_VARIATIONS, 0.9, ["licks"], "Musical phrase"),
            GuitarTerm("riff", TermCategory.COMMON_VARIATIONS, 0.9, ["riffs"], "Repeated phrase"),
            GuitarTerm("solo", TermCategory.COMMON_VARIATIONS, 0.8, [], "Lead guitar part"),
            GuitarTerm("jam", TermCategory.COMMON_VARIATIONS, 0.8, ["jamming"], "Informal playing"),
            GuitarTerm("groove", TermCategory.COMMON_VARIATIONS, 0.7, [], "Rhythmic feel"),
            GuitarTerm("chops", TermCategory.COMMON_VARIATIONS, 0.8, [], "Technical skill"),
        ]
        
        # Combine all term lists
        all_term_lists = [
            guitar_parts, string_terms, playing_techniques, chord_terms,
            scales_modes, musical_theory, effects_equipment, guitar_types,
            notation_tab, maintenance_setup, instruction_terms, common_variations
        ]
        
        # Build the main terms dictionary
        for term_list in all_term_lists:
            for term in term_list:
                # Add main term
                terms[term.term.lower()] = term
                
                # Add aliases
                for alias in term.aliases:
                    terms[alias.lower()] = term
        
        return terms
    
    def _build_lookup_set(self) -> Set[str]:
        """Build a fast lookup set for simple containment checks"""
        return set(self.terms_db.keys())
    
    def is_guitar_term(self, word: str) -> bool:
        """Check if a word is a guitar term"""
        return word.lower().strip() in self.lookup_set
    
    def get_term_info(self, word: str) -> Optional[GuitarTerm]:
        """Get detailed information about a guitar term"""
        return self.terms_db.get(word.lower().strip())
    
    def get_confidence(self, word: str) -> float:
        """Get confidence score for a word being a guitar term"""
        term_info = self.get_term_info(word)
        return term_info.confidence if term_info else 0.0
    
    def get_terms_by_category(self, category: TermCategory) -> List[GuitarTerm]:
        """Get all terms in a specific category"""
        return [term for term in self.terms_db.values() if term.category == category]
    
    def get_all_terms(self) -> List[str]:
        """Get all guitar terms as a list"""
        return list(self.lookup_set)
    
    def get_statistics(self) -> Dict[str, int]:
        """Get statistics about the term library"""
        stats = {"total_terms": len(self.lookup_set)}
        
        # Count by category
        for category in TermCategory:
            count = len([t for t in self.terms_db.values() if t.category == category])
            stats[f"{category.value}_count"] = count
        
        return stats

# Global instance
_guitar_terms_library = None

def get_guitar_terms_library() -> GuitarTermsLibrary:
    """Get the global guitar terms library instance"""
    global _guitar_terms_library
    if _guitar_terms_library is None:
        _guitar_terms_library = GuitarTermsLibrary()
    return _guitar_terms_library

def is_guitar_term(word: str) -> bool:
    """Quick check if a word is a guitar term"""
    return get_guitar_terms_library().is_guitar_term(word)

def get_guitar_term_confidence(word: str) -> float:
    """Get confidence score for a guitar term"""
    return get_guitar_terms_library().get_confidence(word)

# Test execution
if __name__ == "__main__":
    library = get_guitar_terms_library()
    stats = library.get_statistics()
    
    print("Guitar Terms Library Statistics")
    print("=" * 40)
    print(f"Total terms: {stats['total_terms']}")
    print()
    
    for category in TermCategory:
        count = stats.get(f"{category.value}_count", 0)
        print(f"{category.value.replace('_', ' ').title()}: {count}")
    
    print("\nTest Lookups:")
    test_words = ["chord", "fretboard", "hammer-on", "not-guitar-term"]
    for word in test_words:
        is_term = library.is_guitar_term(word)
        confidence = library.get_confidence(word)
        print(f"'{word}': {is_term} (confidence: {confidence:.1f})") 