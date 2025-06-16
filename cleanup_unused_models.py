#!/usr/bin/env python3
"""
Model Cleanup Script - Remove unused or poorly performing models
"""

import subprocess
import json

def get_model_sizes():
    """Get current model sizes"""
    try:
        result = subprocess.run(
            ["docker", "exec", "ollama-service", "ollama", "list"],
            capture_output=True, text=True, timeout=10
        )
        if result.returncode == 0:
            lines = result.stdout.strip().split('\n')[1:]  # Skip header
            models = []
            for line in lines:
                if line.strip():
                    parts = line.split()
                    if len(parts) >= 3:
                        name = parts[0]
                        size = parts[2]
                        models.append({'name': name, 'size': size})
            return models
        return []
    except Exception as e:
        print(f"Error getting models: {e}")
        return []

def calculate_total_space():
    """Calculate total space used by models"""
    models = get_model_sizes()
    total_gb = 0
    
    print("📊 Current Models:")
    for model in models:
        size_str = model['size']
        print(f"   • {model['name']}: {size_str}")
        
        # Extract GB value
        if 'GB' in size_str:
            gb_value = float(size_str.replace('GB', '').strip())
            total_gb += gb_value
    
    print(f"\n💾 Total space used: {total_gb:.1f}GB")
    return models, total_gb

def remove_model(model_name):
    """Remove a specific model"""
    try:
        result = subprocess.run(
            ["docker", "exec", "ollama-service", "ollama", "rm", model_name],
            capture_output=True, text=True, timeout=30
        )
        if result.returncode == 0:
            print(f"✅ Removed {model_name}")
            return True
        else:
            print(f"❌ Failed to remove {model_name}: {result.stderr}")
            return False
    except Exception as e:
        print(f"❌ Error removing {model_name}: {e}")
        return False

def suggest_removals():
    """Suggest models that could be removed"""
    models, total_gb = calculate_total_space()
    
    # Models we want to keep based on our testing
    keep_models = [
        'llama3.2:3b',      # Best performer
        'llama3.1:latest',  # Backup option
        'mistral:7b-instruct'  # Alternative option
    ]
    
    # Models that could be removed
    removal_candidates = []
    for model in models:
        model_name = model['name']
        if not any(keep in model_name for keep in keep_models):
            removal_candidates.append(model)
    
    if removal_candidates:
        print(f"\n🗑️  Suggested Models to Remove:")
        space_saved = 0
        for model in removal_candidates:
            size_str = model['size']
            print(f"   • {model['name']} ({size_str})")
            if 'GB' in size_str:
                space_saved += float(size_str.replace('GB', '').strip())
        
        print(f"\n💾 Space that would be saved: {space_saved:.1f}GB")
        
        # Ask for confirmation
        print(f"\n🔧 To remove these models, run:")
        for model in removal_candidates:
            print(f"   docker exec ollama-service ollama rm {model['name']}")
            
        return removal_candidates
    else:
        print("\n✅ No models recommended for removal")
        return []

def main():
    print("🧹 Model Cleanup Analysis")
    print("=" * 50)
    
    suggest_removals()
    
    print(f"\n🏆 Recommended Configuration (already applied):")
    print(f"   - Primary Model: llama3.2:3b (fastest, best guitar recognition)")
    print(f"   - Backup Models: llama3.1:latest, mistral:7b-instruct")
    print(f"   - Removed: qwen3:14b (slow, poor guitar recognition)")

if __name__ == "__main__":
    main()