<?php

return [
    // Запуск Node-воркера (Playwright-скрипт з каталогу app/).
    // Воркер логіниться кредами конкретного працівника (employees.yaware_password) —
    // глобального сервісного акаунта Yaware немає.
    'node_binary' => env('YAWARE_NODE_BINARY', 'node'),
    'worker_script' => env('YAWARE_WORKER_SCRIPT', base_path('../app/scripts/export-yaware-xls.mjs')),
    'worker_cwd' => env('YAWARE_WORKER_CWD', base_path('../app')),
    'headless' => env('YAWARE_HEADLESS', true),

    // Таймаут генерації одного звіту, секунд.
    'timeout' => (int) env('YAWARE_WORKER_TIMEOUT', 600),

    // Скільки днів після останньої генерації тримати файли звіту на диску
    // (reports:prune-files). Самі дані дня лишаються в історії назавжди.
    'report_files_retention_days' => (int) env('YAWARE_REPORT_FILES_RETENTION_DAYS', 180),
];
