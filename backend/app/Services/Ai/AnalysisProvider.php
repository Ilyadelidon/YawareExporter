<?php

namespace App\Services\Ai;

/**
 * Один AI-провайдер для розбору робочого дня. Контекст дня будує
 * AiAnalysisService — провайдер лише відправляє його моделі й повертає
 * структурований результат за схемою AnalysisPrompt::responseSchema().
 */
interface AnalysisProvider
{
    /** Машинний ключ: саме він приходить у запиті й лягає в daily_analyses.provider. */
    public function name(): string;

    /** Назва для інтерфейсу. */
    public function label(): string;

    /** Змінна .env із ключем — щоб у помилці писати, чого саме бракує. */
    public function envKey(): string;

    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $context  Дані дня (див. AiAnalysisService::buildContext()).
     * @return array{result: array<string, mixed>, model: string, input_tokens: int, output_tokens: int}
     */
    public function analyse(array $context): array;
}
