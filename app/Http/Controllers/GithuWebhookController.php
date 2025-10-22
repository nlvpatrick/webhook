<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GithuWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Verify webhook signature
        if (! $this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $event = $request->header('X-GitHub-Event');
        $payload = $request->all();

        // Only process issue comments (PR comments are issue comments)
        if ($event !== 'issue_comment') {
            return response()->json(['message' => 'Event ignored'], 200);
        }

        // Check if it's a PR comment with /ai trigger
        if (
            ! isset($payload['issue']['pull_request']) ||
            ! str_contains($payload['comment']['body'], '/ai')
        ) {
            return response()->json(['message' => 'Not an AI review request'], 200);
        }

        // Extract PR info
        $repo = $payload['repository']['full_name'];
        $prNumber = $payload['issue']['number'];

        try {
            // Get PR diff from GitHub
            $diff = $this->getPullRequestDiff($repo, $prNumber);

            // Get AI review from Gemini
            $review = $this->getAIReview($diff);

            // Post review comment back to GitHub
            $this->postComment($repo, $prNumber, $review);

            return response()->json(['message' => 'Review posted successfully'], 200);
        } catch (\Exception $e) {
            Log::error('AI Review failed: '.$e->getMessage());

            // Post error comment to PR
            $this->postComment(
                $repo,
                $prNumber,
                '❌ Failed to generate AI review: '.$e->getMessage()
            );

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        if (! $signature) {
            return false;
        }

        $payload = $request->getContent();
        $secret = config('services.github.webhook_secret');
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    private function getPullRequestDiff(string $repo, int $prNumber): string
    {
        $token = config('services.github.token');

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github.v3.diff'])
            ->get("https://api.github.com/repos/{$repo}/pulls/{$prNumber}");

        if ($response->failed()) {
            throw new \Exception('Failed to fetch PR diff: '.$response->body());
        }

        return $response->body();
    }

    private function getAIReview(string $diff): string
    {
        $apiKey = config('services.gemini.api_key');
        $prompt = $this->buildReviewPrompt($diff);

        $response = Http::post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key={$apiKey}",
            [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 2048,
                ],
            ]
        );

        if ($response->failed()) {
            throw new \Exception('Gemini API failed: '.$response->body());
        }

        $result = $response->json();

        return $result['candidates'][0]['content']['parts'][0]['text'] ?? 'No review generated';
    }

    private function postComment(string $repo, int $issueNumber, string $body): void
    {
        $token = config('services.github.token');

        $response = Http::withToken($token)
            ->post("https://api.github.com/repos/{$repo}/issues/{$issueNumber}/comments", [
                'body' => $body,
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to post comment: '.$response->body());
        }
    }

    private function buildReviewPrompt(string $diff): string
    {
        return "You are an expert code reviewer. Please review the following pull request diff and provide constructive feedback, suggestions for improvements, and highlight any potential issues:\n\n{$diff}\n\nProvide your review below:";
    }
}
