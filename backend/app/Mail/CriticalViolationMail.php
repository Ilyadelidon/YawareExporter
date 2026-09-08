<?php

namespace App\Mail;

use App\Models\DailyAnalysis;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Лист керівнику про критичні порушення в дні працівника.
 *
 * Лист — привід для розмови, а не вирок: разом із фактом і його підставою в
 * ньому йде готове питання до працівника, щоб керівнику лишалось тільки його
 * поставити.
 */
class CriticalViolationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array<string, string>>  $violations  Критичні порушення дня.
     */
    public function __construct(
        public DailyAnalysis $analysis,
        public string $employeeName,
        public string $date,
        public array $violations,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Порушення в робочому дні: {$this->employeeName}, {$this->date}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.critical-violation',
            with: [
                'summary' => $this->analysis->result['summary'] ?? '',
                'appUrl' => rtrim((string) config('app.url'), '/'),
            ],
        );
    }
}
