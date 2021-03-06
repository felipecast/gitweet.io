<?php

namespace App\Jobs;

use App\Models\Pipeline;
use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Abraham\TwitterOAuth\TwitterOAuth;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class TweetNewCommit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $pipeline;
    public $message;
    public $pusher;
    public $payload;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Pipeline $pipeline, array $githubPayload)
    {
        $this->pipeline = $pipeline;
        $this->payload = $githubPayload;
        $this->message = $this->getMessage($githubPayload['head_commit']['message'] ?? '');
        $this->pusher = $githubPayload['pusher']['name'] ?? '';
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->shouldPostTweet()) {
            $this->pipeline->increment('count');

            $this->tweet($this->message, $this->pipeline);
        }
    }

    private function getMessage(string $commitMessage)
    {
        if (($pos = strpos($commitMessage, "\n\n")) !== false) {
            return trim(substr($commitMessage, $pos + 1));
        }

        return null;
    }

    private function shouldPostTweet(): bool
    {
        return !empty($this->message) && $this->pipeline->twitter_access_code && !$this->isPusherABot();
    }

    public function isPusherABot(): bool
    {
        $keywords = [
            '[bot]',
            'dependabot'
        ];

        foreach ([
            $this->pusher,
            $this->payload['ref'],
            $this->payload['commits'][0]['author']['username'] ?? ''
        ] as $haystack) {
            if (Str::contains($haystack, $keywords)) {
                return true;
            }
        }

        return false;
    }

    private function tweet(string $message, Pipeline $pipeline)
    {
        $connection = new TwitterOAuth(
            config('services.twitter.client_id'),
            config('services.twitter.client_secret'),
            $pipeline->twitter_access_code,
            $pipeline->twitter_access_code_secret
        );

        $connection->post('statuses/update', ['status' => $message]);
    }
}
