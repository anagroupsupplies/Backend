<?php

namespace App\Services;

use App\Models\SellerApplication;
use App\Models\User;
use App\Notifications\SellerApplicationStatusNotification;
use Throwable;

/**
 * Seller application emails.
 *
 * Best-effort, like the other notifiers: a mail failure must never stop an
 * application from being submitted or a decision from being recorded.
 */
class SellerApplicationNotifier
{
    public function submitted(SellerApplication $application): void
    {
        $this->toApplicant($application, 'submitted');

        foreach ($this->administrators() as $admin) {
            $this->send(
                fn () => $admin->notify(new SellerApplicationStatusNotification($application, 'admin_alert')),
                "new application alert to {$admin->email}",
            );
        }
    }

    public function approved(SellerApplication $application): void
    {
        $this->toApplicant($application, 'approved');
    }

    public function rejected(SellerApplication $application): void
    {
        $this->toApplicant($application, 'rejected');
    }

    public function moreInformationRequested(SellerApplication $application): void
    {
        $this->toApplicant($application, 'more_info');
    }

    private function toApplicant(SellerApplication $application, string $event): void
    {
        if ($applicant = $application->user) {
            $this->send(
                fn () => $applicant->notify(new SellerApplicationStatusNotification($application, $event)),
                "{$event} email for {$application->reference}",
            );
        }
    }

    /** @return array<int, User> */
    private function administrators(): array
    {
        return User::whereIn('role', ['admin', 'master'])->where('is_active', true)->get()->all();
    }

    private function send(callable $callback, string $description): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning("Failed to send {$description}: {$exception->getMessage()}");
        }
    }
}
