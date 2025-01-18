<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PrintJobEvent implements ShouldBroadcast
{
    use SerializesModels;

    public $htmlContent;
    public $userId;
    public $type;

    public function __construct($htmlContent, $type, $userId)
    {
        $this->htmlContent = $htmlContent;
        $this->type = $type;
        $this->userId = $userId;

        Log::info("PrintJob: ", ["htmlContent" => $this->htmlContent]);
        Log::info("PrintJob: ", ["type" => $this->type]);
        Log::info("PrintJob: ", ["userId", $this->userId]);
    }

    public function broadcastOn()
    {
        return new PrivateChannel('print-jobs.' . $this->userId);
    }

    public function broadcastWith()
    {
        Log::info($this->htmlContent);
        return [
            'type' => $this->type,
            'content' => $this->htmlContent,
        ];
    }

    public function broadcastAs()
    {
        return 'print.job.dispatched';
    }
}
