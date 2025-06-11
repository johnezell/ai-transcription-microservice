# Plan for Refactoring and Optimizing the Transcription Service

## 1. Objective

The primary goal is to significantly increase the throughput of the audio transcription pipeline for processing over 100,000 files on a single, powerful PC. This will be achieved by implementing GPU batch processing. A secondary goal is to refactor the existing transcription service code for improved readability and maintainability, which is a prerequisite for adding the batching feature. Accuracy must be maintained at the highest level throughout the process.

## 2. Guiding Principles

-   **Accuracy First:** No changes should compromise the quality of the transcription. Methods like quantization that trade accuracy for speed will be avoided.
-   **Single-PC Optimization:** The architecture must be optimized for a single-machine environment with one GPU. This means avoiding unnecessary network/disk I/O between processing steps.
-   **Bottleneck Targeting:** The main bottleneck is the one-by-one, serial processing of files on the GPU. The solution must directly address this by enabling parallel processing *on the GPU itself*.

## 3. Phase 1: Service-Internal Refactoring

This phase focuses on improving the code structure of `app/services/transcription/service.py` without changing its functionality. This makes the code safer and easier to modify in the next phase.

### Step 1.1: Consolidate Post-Processing Logic

-   **Action:** Create a new private helper function within `service.py` named `_run_post_processing`.
-   **Signature:** `_run_post_processing(transcription_result: Dict, audio_path: str, preset_config: Dict) -> Dict`
-   **Purpose:** This function will encapsulate all the steps that occur *after* the initial transcription is complete.

### Step 1.2: Move Existing Logic

-   **Action:** Cut all the post-processing code from the `_process_audio_core` function and paste it into the new `_run_post_processing` function.
-   **Code to be moved includes:**
    1.  Word Alignment (`whisperx.align`).
    2.  Speaker Diarization (`whisperx.assign_word_speakers`).
    3.  Guitar Terminology Enhancement (`enhance_guitar_terminology`).
    4.  Final calculation of `confidence_score` and `quality_metrics`.
    5.  Generation of the final `text` and `word_segments` from the segment data.

### Step 1.3: Streamline the Core Processing Function

-   **Action:** Modify the `_process_audio_core` function.
-   **New Logic:** Its sole responsibilities should now be:
    1.  Loading the model.
    2.  Performing the initial transcription (`model.transcribe`).
    3.  Calling `_run_post_processing` with the initial result.
    4.  Wrapping the final, enhanced result with performance metrics and returning it.
-   **Outcome:** `_process_audio_core` becomes much shorter, cleaner, and easier to understand. The separation of concerns is clear: one function for transcription, another for enhancement.

## 4. Phase 2: Batch Processing Implementation

This phase implements the core performance enhancement. We will modify the system to send a *batch* of audio files to the GPU at once.

### Step 2.1: Modify the Python Service Endpoint

-   **File:** `app/services/transcription/service.py`
-   **Action:** Update the `/transcribe` endpoint.
-   **Input:** Change the expected JSON payload to accept a list of file paths. The key should be changed from `"audio_path"` to `"audio_paths"`.
    ```json
    {
      "job_id": "batch_123",
      "audio_paths": [
        "/path/to/file1.wav",
        "/path/to/file2.wav",
        "/path/to/file3.wav"
      ],
      "preset": "balanced"
    }
    ```
-   **Logic Change:**
    1.  Load the WhisperX model once.
    2.  Instead of calling `model.transcribe` in a loop, call `model.transcribe_batch(audio_paths_list, ...)`. This is a native, highly optimized WhisperX function.
    3.  The `transcribe_batch` method will return a list of transcription results.
    4.  Iterate through this list of results. For each result, call the `_run_post_processing` function created in Phase 1.
    5.  Collect the fully processed results and return them as a list in the final API response.

### Step 2.2: Create a Laravel Batching Mechanism

-   **Goal:** Group individual transcription requests into batches before sending them to the Python service.
-   **Action:** Create a new Laravel Job, for example `ProcessTranscriptionBatchJob`.
-   **Trigger:** This job can be scheduled to run every minute or triggered when the number of pending items reaches a certain threshold.
-   **Logic:**
    1.  This job runs on the dedicated `transcription` queue and should only have **one** worker.
    2.  It queries the database for all segments that are in the `audio_extracted` state.
    3.  It pulls a configurable number of these segments (e.g., a batch size of 4 or 8).
    4.  It marks these segments as `transcribing` to prevent other workers from picking them up.
    5.  It constructs the list of audio file paths for the batch.
    6.  It makes a **single** API call to the `/transcribe` endpoint (modified in Step 2.1), sending the entire list of paths.
    7.  The response will contain results for all files in the batch. The job will then loop through the results and update each corresponding database record with the final transcript data.

This plan systematically refactors the code for maintainability and then implements the crucial batch processing step, which will provide the desired performance increase without sacrificing accuracy. 