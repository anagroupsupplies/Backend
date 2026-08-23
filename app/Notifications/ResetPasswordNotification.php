<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Administrators sign in on the admin panel, so their reset link has to
        // land there rather than on the storefront.
        $base = rtrim($notifiable->isAdmin() ? config('app.admin_url') : config('app.frontend_url'), '/');
        $url = $base.'/reset-password?token='.urlencode($this->token).'&email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset your Antenkayume Shop password')
            ->view('emails.reset-password', ['customer' => $notifiable, 'url' => $url, 'expires' => config('auth.passwords.users.expire')])
            ->text('emails.text.reset-password', ['customer' => $notifiable, 'url' => $url, 'expires' => config('auth.passwords.users.expire')]);
    }
}
