# Local Laravel Development Setup Plan

This plan outlines the steps to configure a local Laravel development environment that can interact with the deployed AWS Aurora database and S3 bucket, while mocking the calls to external microservices (Audio Extraction, Transcription) for faster iteration.

## Prerequisites:

1.  **VPN Access:** Ensure your local machine can connect to the company VPN and that the VPN provides a route to the AWS VPC where the Aurora database resides.
2.  **AWS CLI Configured:** Your local AWS CLI should be configured with the `tfs-shared-services` profile, and this profile's IAM user/role must have necessary permissions for S3 (GetObject, PutObject, DeleteObject, ListBucket on `aws-transcription-data-ACCOUNT-REGION`) and Secrets Manager (GetSecretValue for the DB secret).
3.  **Local Laravel Project:** The `app/laravel` project should be runnable locally (e.g., `php artisan serve`, `npm install`, `npm run dev`).
4.  **Workspace Root:** Commands are assumed to be run from the workspace root (`/Users/john/code/aws-transcription-service-je`) or relative to it as specified.

## Steps:

### 1. Verify Network Connectivity to Aurora DB

*   **Action:** While connected to VPN, attempt to connect to the Aurora DB cluster using a MySQL client (e.g., MySQL Workbench, `mysql` CLI, TablePlus) using the details from Step 2.
*   **Goal:** Confirm direct network reachability to the database endpoint.

### 2. Gather AWS Resource Details

*   **Aurora Database:**
    *   **Cluster Endpoint Hostname:** Get from `CdkInfraStack` output `DbClusterEndpointOutput` (e.g., `cdkinfrastack-appdatabaseclustere84d9f40-oapmw4tkdvbs.cluster-cmpvpqjkde3z.us-east-1.rds.amazonaws.com`).
    *   **Database Name:** `appdb` (or as defined in `CdkInfraStack`).
    *   **Master Username & Password:** Retrieve from AWS Secrets Manager using the ARN from `CdkInfraStack` output `DbClusterSecretArnOutput`.
        *   CLI Command: `aws secretsmanager get-secret-value --secret-id YOUR_DB_SECRET_ARN --query SecretString --output text --profile tfs-shared-services --region us-east-1`
*   **S3 Bucket:**
    *   **Bucket Name:** Get from `CdkInfraStack` output `AppDataBucketNameOutput` (e.g., `aws-transcription-data-542876199144-us-east-1`).
    *   **Region:** `us-east-1`.

### 3. Configure Local Laravel `.env` File

*   **Location:** `app/laravel/.env` (create from `app/laravel/.env.example` if it doesn't exist).
*   **Ensure it's in `.gitignore`** at the root of the Laravel project (`app/laravel/.gitignore`).
*   **Settings:**
    ```env
    APP_ENV=local
    APP_DEBUG=true
    APP_URL=http://localhost:8000 # Or your php artisan serve port

    # Queues (ensure this is set to sync for local dev if you don't want to run a separate worker immediately)
    # Or set to 'database' or 'redis' if you have a local queue worker setup.
    # For initial simplicity, 'sync' might be easiest, though jobs won't be backgrounded.
    QUEUE_CONNECTION=sync 

    DB_CONNECTION=mysql
    DB_HOST= # Paste Aurora Cluster Endpoint Hostname here
    DB_PORT=3306
    DB_DATABASE=appdb # Or your DB name
    DB_USERNAME= # Paste Master Username here
    DB_PASSWORD='' # Paste Master Password here (use single quotes if it contains special characters)

    # Option 1: Local filesystem for local dev, manual S3 interaction if needed
    # FILESYSTEM_DISK=public 
    # Option 2: Directly use S3 for local dev (requires AWS creds to be set up)
    FILESYSTEM_DISK=s3

    AWS_ACCESS_KEY_ID= # Leave blank if AWS CLI profile handles this
    AWS_SECRET_ACCESS_KEY= # Leave blank if AWS CLI profile handles this
    AWS_DEFAULT_REGION=us-east-1
    AWS_BUCKET= # Paste S3 Bucket Name here
    AWS_USE_PATH_STYLE_ENDPOINT=false

    # Mocked service URLs (won't be called if APP_ENV=local and jobs are modified)
    AUDIO_SERVICE_URL=http://mock-audio.internal.test
    TRANSCRIPTION_SERVICE_URL=http://mock-transcription.internal.test
    # MUSIC_TERM_SERVICE_URL=http://mock-music-term.internal.test

    # Ensure APP_KEY is set (php artisan key:generate if needed)
    # Add any other essential Laravel environment variables.
    ```

### 4. Modify Laravel Jobs for Local Mocking

*   **Import `App` Facade:** Add `use Illuminate\Support\Facades\App;` to the top of both job files.
*   **`app/laravel/app/Jobs/AudioExtractionJob.php`:**
    *   Inside `handle()` method, add at the beginning:
        ```php
        if (App::environment('local')) {
            Log::info('[AudioExtractionJob LOCAL] Simulating audio extraction success.', ['video_id' => $this->video->id]);
            $dummyAudioS3Key = 's3/jobs/' . $this->video->id . '/mock_audio.wav';
            // To make it more realistic for other parts of the app expecting S3 files:
            // if (!Storage::disk('s3')->exists($dummyAudioS3Key)) { 
            //     Storage::disk('s3')->put($dummyAudioS3Key, 'This is a dummy audio file for local dev.');
            // }
            $this->video->update([
                'status' => 'audio_extracted',
                'audio_path' => $dummyAudioS3Key,
                'audio_duration' => rand(60, 300) + (rand(0, 99) / 100), // Random duration
                'audio_size' => rand(1000000, 5000000),   // Random size
            ]);
            $log = \App\Models\TranscriptionLog::firstOrCreate(
                ['video_id' => $this->video->id],
                ['job_id' => $this->video->id, 'started_at' => now()]
            );
            $log->update([
                'status' => 'audio_extracted',
                'audio_extraction_completed_at' => now(),
                'audio_file_size' => $this->video->audio_size,
                'audio_duration_seconds' => $this->video->audio_duration,
                'progress_percentage' => 50,
            ]);
            Log::info('[AudioExtractionJob LOCAL] Dispatching TranscriptionJob.', ['video_id' => $this->video->id]);
            self::dispatch($this->video); // Dispatch TranscriptionJob if it's part of the mocked flow
                                           // Or change status to 'transcribed' if TranscriptionJob is also fully mocked here.
            return; 
        }
        ```
*   **`app/laravel/app/Jobs/TranscriptionJob.php`:**
    *   Inside `handle()` method, add at the beginning:
        ```php
        if (App::environment('local')) {
            Log::info('[TranscriptionJob LOCAL] Simulating transcription success.', ['video_id' => $this->video->id]);
            $baseS3Key = 's3/jobs/' . $this->video->id . '/';
            $dummyTranscriptTxtKey = $baseS3Key . 'mock_transcript.txt';
            $dummyTranscriptSrtKey = $baseS3Key . 'mock_transcript.srt';
            $dummyTranscriptJsonKey = $baseS3Key . 'mock_transcript.json';
            $mockedText = "This is a locally mocked transcript for video {$this->video->id}. Lorem ipsum dolor sit amet.";
            $mockedSegments = [['text' => 'Mocked segment 1.', 'start' => 0, 'end' => 2], ['text' => 'Mocked segment 2.', 'start' => 2, 'end' => 4]];
            // if (!Storage::disk('s3')->exists($dummyTranscriptTxtKey)) { Storage::disk('s3')->put($dummyTranscriptTxtKey, $mockedText); }
            // if (!Storage::disk('s3')->exists($dummyTranscriptSrtKey)) { Storage::disk('s3')->put($dummyTranscriptSrtKey, "1\n00:00:00,000 --> 00:00:02,000\nMocked segment 1.\n\n2\n00:00:02,000 --> 00:00:04,000\nMocked segment 2.\n"); }
            // if (!Storage::disk('s3')->exists($dummyTranscriptJsonKey)) { Storage::disk('s3')->put($dummyTranscriptJsonKey, json_encode(['text' => $mockedText, 'segments' => $mockedSegments])); }

            $this->video->update([
                'status' => 'transcribed', // Or 'completed' if no terminology step follows
                'transcript_path' => $dummyTranscriptTxtKey,
                // 'transcript_srt_path' => $dummyTranscriptSrtKey, // Add if you have this DB field
                // 'transcript_json_path' => $dummyTranscriptJsonKey, // Add if you have this DB field
                'transcript_text' => $mockedText,
                'transcript_json' => ['text' => $mockedText, 'segments' => $mockedSegments, 'language' => 'en'],
            ]);
            $log = \App\Models\TranscriptionLog::where('video_id', $this->video->id)->first();
            if ($log) {
                $log->update([
                    'status' => 'transcribed',
                    'transcription_completed_at' => now(),
                    'progress_percentage' => 75, // Assuming terminology is next, otherwise 100
                ]);
            }
            // If TerminologyRecognitionJob exists and should be part of the mock flow:
            // \App\Jobs\TerminologyRecognitionJob::dispatch($this->video);
            // else, update to completed:
            // $this->video->update(['status' => 'completed']);
            // if ($log) { $log->update(['status' => 'completed', 'completed_at' => now(), 'progress_percentage' => 100]); }
            Log::info('[TranscriptionJob LOCAL] Mock processing complete.', ['video_id' => $this->video->id]);
            return; 
        }
        ```
    *   **Note on `TranscriptionJob` Mock:** Adjust the final status (`transcribed` vs `completed`) depending on whether you also mock the terminology step or if transcription is the last mocked step.

### 5. Local Development Workflow Execution

1.  **VPN:** Ensure VPN is active for DB access.
2.  **AWS Credentials:** Ensure local AWS CLI profile (`tfs-shared-services`) is active/default or AWS environment variables for credentials are set if not using a profile directly from SDK.
3.  **Terminal 1 (Laravel App Server):**
    *   `cd app/laravel`
    *   `php artisan serve --port=8000` (or your preferred port)
4.  **Terminal 2 (Vite Frontend Dev Server):**
    *   `cd app/laravel`
    *   `npm run dev`
5.  **Terminal 3 (Optional - Queue Worker):**
    *   If `QUEUE_CONNECTION` in `.env` is not `sync` (e.g., `database` or `redis`):
    *   `cd app/laravel`
    *   `php artisan queue:work`
    *   If `QUEUE_CONNECTION=sync`, jobs run immediately during the request, so no separate worker is needed, but UI might feel slower.
6.  **Access Application:** Open `http://localhost:5173` (or your Vite port, which proxies to `php artisan serve`) in your browser.
7.  **Test:** Upload a video. Observe S3 uploads, database updates (via local MySQL client), and Laravel logs. The UI should reflect the mocked processing steps.

### 6. Future Considerations (Out of Scope for this Initial Plan)

*   **Local Microservices:** Running Python services (Audio, Transcription) locally using Docker Compose.
*   **Service Discovery for Local:** Using `/etc/hosts` or a local DNS resolver if local Laravel needs to call locally running Python services.
*   **Callbacks to Local Laravel:** Using `ngrok` or similar if deployed Python services need to call back to local Laravel during specific tests.

---
*This plan should be reviewed and adjusted as needed.* 