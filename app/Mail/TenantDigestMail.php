<?php

namespace App\Mail;

use App\Support\TenantDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The daily summary for tenants: new charges, changed amounts, payments received and the balance.
 */
class TenantDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TenantDigest $digest) {}

    public function envelope(): Envelope
    {
        $owners = $this->digest->lease->apartment->owners;

        return new Envelope(
            subject: $this->digest->subject(),
            replyTo: $owners->map(fn ($owner) => new Address($owner->email, $owner->name))->all(),
        );
    }

    public function content(): Content
    {
        $lease = $this->digest->lease;

        return new Content(
            markdown: 'mail.tenant-digest',
            with: [
                'digest' => $this->digest,
                'lease' => $lease,
                'statement' => $this->digest->statement,
                'greetingNames' => $lease->tenants->pluck('first_name')->join(', ', ' i '),
                'ownerNames' => $lease->apartment->owners->pluck('name')->join(', ', ' i '),
            ],
        );
    }
}
