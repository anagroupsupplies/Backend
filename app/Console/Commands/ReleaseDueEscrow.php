<?php

namespace App\Console\Commands;

use App\Services\EscrowService;
use Illuminate\Console\Command;

class ReleaseDueEscrow extends Command
{
    protected $signature = 'escrow:release-due';

    protected $description = 'Release escrowed funds whose buyer inspection window has elapsed';

    public function handle(EscrowService $escrow): int
    {
        $released = $escrow->releaseDue();

        $this->info($released === 0
            ? 'No escrow holdings were due for release.'
            : "Released {$released} escrow holding(s) to sellers.");

        return self::SUCCESS;
    }
}
