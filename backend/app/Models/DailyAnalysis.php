<?php

namespace App\Models;

use App\Services\Ai\AnalysisPrompt;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'date',
    'status',
    'provider',
    'result',
    'model',
    'input_tokens',
    'output_tokens',
    'error_message',
    'generated_at',
    'alerted_at',
])]
class DailyAnalysis extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'result' => 'array',
            'generated_at' => 'datetime',
            'alerted_at' => 'datetime',
        ];
    }

    /**
     * Критичні порушення дня — те, через що керівнику йде лист.
     *
     * @return list<array<string, string>>
     */
    public function criticalViolations(): array
    {
        $violations = $this->result['violations'] ?? [];

        if (! is_array($violations)) {
            return [];
        }

        return array_values(array_filter(
            $violations,
            fn ($violation) => is_array($violation)
                && ($violation['severity'] ?? null) === AnalysisPrompt::SEVERITY_CRITICAL,
        ));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
