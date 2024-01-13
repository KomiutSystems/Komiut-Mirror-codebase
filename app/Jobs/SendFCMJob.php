<?php

namespace App\Jobs;

use App\Http\Controllers\Services\SendFCMMessageController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFCMJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    protected $tokens;
    protected $title;
    protected $message;
    protected $payload;
    public function __construct($tokens, $title, $message, $payload)
    {
        $this->tokens = $tokens;
        $this->title = $title;
        $this->message = $message;
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        (new SendFCMMessageController)->sendFCMNotification($this->tokens, $this->title, $this->message, $this->payload);
    }
}
