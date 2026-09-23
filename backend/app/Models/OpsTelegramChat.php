<?php

namespace App\Models;

use App\Services\TelegramService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Чат, у який адміністратор отримує технічні сповіщення: підсумок ранкового
 * прогону і тривоги монітора. Чатів може бути кілька — особистий, спільна
 * група підтримки, черговий канал, — і кожен підключається окремим Start у
 * бота. Це не те саме, що telegram_chat_id: там особисті сповіщення про звіти.
 */
#[Fillable(['user_id', 'chat_id', 'title'])]
class OpsTelegramChat extends Model
{
    /** Скільки чатів один адміністратор може тримати підключеними. */
    public const MAX_PER_USER = 5;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Підпис чату для списку. Порожній title означає, що Telegram назви ще не
     * дав (або не відповів), — тоді лишається хоч номер чату, щоб рядок не був
     * порожнім.
     */
    public function label(): string
    {
        return $this->title ?: "Чат {$this->chat_id}";
    }

    /**
     * Група чи особистий чат. Telegram видає групам і каналам відʼємні id —
     * інших ознак у нас немає, та більше й не треба: у списку це лише підпис.
     */
    public function isGroup(): bool
    {
        return str_starts_with($this->chat_id, '-');
    }

    /**
     * Питає назву в самого Telegram — для чатів, підключених до того, як ми
     * почали її запамʼятовувати. Мовчить при збої: підпис у списку не варто
     * того, щоб через нього падали налаштування.
     */
    public function syncTitle(TelegramService $telegram): void
    {
        $chat = $telegram->fetchChat($this->chat_id);

        if ($chat && $title = self::titleFrom($chat)) {
            $this->update(['title' => $title]);
        }
    }

    /**
     * Назва чату з даних Telegram: у групи це title, в особистому — імʼя
     * і @нік. Нік показуємо поруч з імʼям, бо саме за ним користувач упізнає
     * свій чат, а імена в Telegram нерідко однакові.
     */
    public static function titleFrom(array $chat): ?string
    {
        $title = trim((string) ($chat['title'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        $name = trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? ''));
        $username = ! empty($chat['username']) ? '@'.$chat['username'] : '';

        $title = trim($name !== '' && $username !== '' ? "{$name} ({$username})" : $name.$username);

        return $title !== '' ? $title : null;
    }
}
