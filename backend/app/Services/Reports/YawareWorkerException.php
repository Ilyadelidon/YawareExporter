<?php

namespace App\Services\Reports;

use RuntimeException;
use Throwable;

/**
 * Воркер не згенерував звіт. Повідомлення готове до показу працівнику;
 * $workerCode — код помилки, який віддав сам воркер (null — він упав без
 * структурованої помилки: таймаут, крах процесу, битий вивід).
 */
class YawareWorkerException extends RuntimeException
{
    // Коди воркера, за яких повтор дасть той самий результат.
    private const PERMANENT_CODES = [
        'INVALID_CREDENTIALS',
        'REPORTS_PAGE_UNAVAILABLE',
        'EMPTY_DAY',
    ];

    public function __construct(string $message, public readonly ?string $workerCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Чи може повтор дати інший результат. Падіння без коду вважаємо
     * тимчасовим.
     */
    public function isRetryable(): bool
    {
        return ! in_array($this->workerCode, self::PERMANENT_CODES, true);
    }
}
