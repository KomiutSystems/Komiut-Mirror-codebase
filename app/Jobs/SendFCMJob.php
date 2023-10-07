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
    public function __construct($tokens, $title, $message)
    {
        $this->tokens = $tokens;
        $this->title = $title;
        $this->message = $message;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        (new SendFCMMessageController)->sendFCMNotification($this->tokens, $this->title, $this->message);
    }
}
