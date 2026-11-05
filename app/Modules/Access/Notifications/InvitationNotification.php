<?php

namespace App\Modules\Access\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/set-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]));
        $hours = (int) config('auth.passwords.invitations.expire') / 60;

        return (new MailMessage)
            ->subject('You have been invited to '.config('app.name'))
            ->greeting('Hello '.$notifiable->name.',')
            ->line('An account has been created for you on '.config('app.name').'.')
            ->action('Set your password', $url)
            ->line("This link expires in {$hours} hours. If it has expired, ask your administrator to send a new one.");
    }
}
