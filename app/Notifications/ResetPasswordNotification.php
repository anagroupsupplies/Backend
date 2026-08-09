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
        $frontend = rtrim(config('app.frontend_url'), '/');
        $url = $frontend.'/reset-password?token='.urlencode($this->token).'&email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset your Antenkayume Shop password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset your password.')
            ->action('Reset password', $url)
            ->line('This password-reset link expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you did not request a password reset, no action is required.');
    }
}
