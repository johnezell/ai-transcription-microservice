<?php

namespace App\Http\Controllers;

use App\Services\CloudFrontSigningService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CloudFrontController extends Controller
{
    private CloudFrontSigningService $cloudFrontService;

    public function __construct(CloudFrontSigningService $cloudFrontService)
    {
        $this->cloudFrontService = $cloudFrontService;
    }

    /**
     * Sign a single CloudFront URL
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function signUrl(Request $request): JsonResponse
    {
        $request->validate([
            'server' => 'required|string',
            'file' => 'string',
            'seconds' => 'integer|min:1|max:86400', // Max 24 hours
            'whitelist' => 'boolean'
        ]);

        try {
            $signedUrl = $this->cloudFrontService->signUrl(
                $request->input('server'),
                $request->input('file', ''),
                $request->input('seconds', 300),
                $request->input('whitelist', false)
            );

            return response()->json([
                'success' => true,
                'signed_url' => $signedUrl,
                'expires_in' => $request->input('seconds', 300)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sign multiple CloudFront URLs
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function signMultipleUrls(Request $request): JsonResponse
    {
        $request->validate([
            'urls' => 'required|array',
            'urls.*' => 'required|string',
            'seconds' => 'integer|min:1|max:86400',
            'whitelist' => 'boolean'
        ]);

        try {
            $signedUrls = $this->cloudFrontService->signMultipleUrls(
                $request->input('urls'),
                $request->input('seconds', 300),
                $request->input('whitelist', false)
            );

            return response()->json([
                'success' => true,
                'signed_urls' => $signedUrls,
                'expires_in' => $request->input('seconds', 300)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate CloudFront configuration
     *
     * @return JsonResponse
     */
    public function validateConfiguration(): JsonResponse
    {
        $isValid = $this->cloudFrontService->validateConfiguration();

        return response()->json([
            'success' => $isValid,
            'message' => $isValid 
                ? 'CloudFront configuration is valid' 
                : 'CloudFront configuration is invalid'
        ]);
    }
}