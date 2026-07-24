<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password-reset email that links to the FRONTEND reset page (the Next.js
 * dashboard), not a backend web route — this is an API-only backend. The token
 * and email travel as query params the SPA reads and posts back to
 * /api/v1/auth/reset-password.
 */
class ResetPasswordLink extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = $base . '/reset-password?token=' . $this->token
            . '&email=' . urlencode($notifiable->getEmailForPasswordReset());

        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your Komiut password')
            ->greeting('Password reset')
            ->line('We received a request to reset your password.')
            ->action('Reset password', $url)
            ->line("This link expires in {$minutes} minutes.")
            ->line("If you didn't request this, you can safely ignore this email.");
    }
}
