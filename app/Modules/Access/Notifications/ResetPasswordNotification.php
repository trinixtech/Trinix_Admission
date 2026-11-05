<?php

namespace App\Modules\Access\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/reset-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]));

        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->line('We received a request to reset your password.')
            ->action('Reset password', $url)
            ->line('This link expires in '.config('auth.passwords.users.expire').' minutes. If you did not ask for this, ignore this email.');
    }
}
