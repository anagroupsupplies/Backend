<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'user' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);

        return (new MailMessage)
            ->subject('Verify your Antenkayume Shop account')
            ->greeting('Welcome to Antenkayume Shop!')
            ->line('Please verify your email address to secure your account and continue to checkout.')
            ->action('Verify email address', $url)
            ->line('This verification link expires in 60 minutes. If you did not create this account, no action is required.');
    }
}
