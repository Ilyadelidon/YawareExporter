<?php

namespace App\Services;

use RuntimeException;

/**
 * Авторизація працівника в Бітріксі не вдалась. Повідомлення — готове
 * пояснення для людини: його показує сторінка інтеграцій.
 */
class BitrixLinkFailed extends RuntimeException {}
