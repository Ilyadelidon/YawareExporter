<?php

namespace App\Support;

class WorkerEnvironment
{
    /**
     * Системні змінні, без яких Node/Playwright не запуститься.
     * У веб-контексті ($_ENV порожній) Symfony Process їх не успадковує,
     * тому передаємо явно через getenv(). Windows- і Linux-специфічні
     * імена в одному списку — відсутні просто пропускаються.
     * HOME/XDG_CACHE_HOME потрібні Playwright на Linux (кеш браузерів),
     * PLAYWRIGHT_BROWSERS_PATH і YAWARE_PYTHON_BINARY — явні перевизначення.
     */
    public static function base(): array
    {
        $names = [
            'PATH', 'TEMP', 'TMP', 'SYSTEMROOT', 'SYSTEMDRIVE', 'USERPROFILE',
            'LOCALAPPDATA', 'APPDATA', 'PROGRAMFILES', 'COMSPEC',
            'HOME', 'XDG_CACHE_HOME', 'PLAYWRIGHT_BROWSERS_PATH', 'YAWARE_PYTHON_BINARY',
        ];
        $env = [];

        foreach ($names as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $env[$name] = $value;
            }
        }

        $env['TEMP'] ??= sys_get_temp_dir();
        $env['TMP'] ??= sys_get_temp_dir();

        return $env;
    }
}
