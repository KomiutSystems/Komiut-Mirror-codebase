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
    protected $booking_id;
    public function __construct($tokens, $title, $message, $payload, $booking_id)
    {
        $this->tokens = $tokens;
        $this->title = $title;
        $this->message = $message;
        $this->payload = $payload;
        $this->booking_id = $booking_id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //\Log::info("Tunacheki kitu kama iko sawa!!$this->booking_id");
        (new SendFCMMessageController)->sendFCMNotification($this->tokens, $this->title, $this->message, $this->payload, $this->booking_id);
    }
}
