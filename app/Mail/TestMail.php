<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Simple email sent by "php artisan mail:test" to check the SMTP settings.
 */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.test.subject', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>'.e(__('mail.test.body')).'</p>',
        );
    }
}
