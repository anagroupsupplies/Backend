<?php

namespace App\Notifications;

use App\Models\SellerApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SellerApplicationStatusNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $event  submitted|approved|rejected|more_info|admin_alert
     */
    public function __construct(public SellerApplication $application, public string $event) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = [
            'application' => $this->application,
            'recipient' => $notifiable,
            'event' => $this->event,
            'headline' => $this->headline(),
            'body' => $this->body(),
        ];

        return (new MailMessage)
            ->subject($this->subject())
            ->view('emails.seller-application', $data)
            ->text('emails.text.seller-application', $data);
    }

    private function subject(): string
    {
        $business = $this->application->business_name;

        return match ($this->event) {
            'submitted' => "We received your seller application ({$this->application->reference})",
            'approved' => 'Your shop is approved — you can start selling',
            'rejected' => "Your seller application was not approved ({$this->application->reference})",
            'more_info' => 'We need a bit more information about your shop',
            default => "New seller application: {$business}",
        };
    }

    private function headline(): string
    {
        return match ($this->event) {
            'submitted' => 'Application received 📩',
            'approved' => 'Welcome aboard 🎉',
            'rejected' => 'Application not approved',
            'more_info' => 'We need more information',
            default => 'New seller application',
        };
    }

    private function body(): string
    {
        $business = $this->application->business_name;

        return match ($this->event) {
            'submitted' => "Thank you for applying to sell on Antenkayume. Your application for {$business} is now <strong>Pending Approval</strong>. Our team reviews applications in the order they arrive and you will get an email as soon as there is a decision.",
            'approved' => "Your application for {$business} has been approved. Your account is now a seller account, so you can sign in and open your seller dashboard to add products, manage orders and track your earnings.",
            'rejected' => "We were unable to approve your application for {$business} at this time.",
            'more_info' => "Before we can finish reviewing {$business}, we need a little more from you. Please sign in, update your application and submit it again.",
            default => "{$business} has applied to sell on the platform and is waiting for review.",
        };
    }
}
