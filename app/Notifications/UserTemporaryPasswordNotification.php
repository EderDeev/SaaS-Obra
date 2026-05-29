<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserTemporaryPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Tenant $tenant,
        private readonly string $temporaryPassword,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loginUrl = route('tenant.dashboard', $this->tenant);

        return (new MailMessage)
            ->subject('Seu acesso ao Deming')
            ->view('emails.user-temporary-password', [
                'tenant' => $this->tenant,
                'user' => $notifiable,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => $loginUrl,
            ])
            ->text('emails.user-temporary-password-text', [
                'tenant' => $this->tenant,
                'user' => $notifiable,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => $loginUrl,
            ]);
    }
}
