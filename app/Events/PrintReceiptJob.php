<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PrintReceiptJob implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public int $userId;
    public array $sales;

    /**
     * Create a new event instance.
     */
    public function __construct(int $userId, array $sales)
    {
        $this->userId = $userId;
        $this->sales = $sales;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new Channel("print-receipt-job.{$this->userId}");
    }

    /**
     * The event's broadcast name (optional, defaults to class name).
     */
    public function broadcastAs(): string
    {
        return 'PrintReceiptJob';
    }

    /**
     * Data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'sales' => $this->sales,
        ];
    }
}
