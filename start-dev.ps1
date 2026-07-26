# Запускає всі процеси сервісу звітності в окремих вікнах:
#   1. Laravel API        (http://localhost:8000)
#   2. Обробники черг     (logins — перевірки логіну, default — генерація звітів)
#   3. Vue-фронтенд       (відкриє браузер сам)
# Зупинити все — просто закрити відповідні вікна.

$root = $PSScriptRoot

function Test-PortBusy([int]$Port) {
    return [bool](Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}

if (Test-PortBusy 8000) {
    Write-Host "Порт 8000 вже зайнятий — схоже, API вже запущено. Пропускаю." -ForegroundColor Yellow
} else {
    Start-Process powershell -ArgumentList '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle = 'Yaware API :8000'; Set-Location '$root\backend'; php artisan serve --port=8000"
    Write-Host "API запущено на http://localhost:8000" -ForegroundColor Green
}

# Два воркери: logins — швидкі Playwright-перевірки логіну (щоб вхід не чекав
# за довгими звітами), default — генерація звітів.
$workers = @(
    @{ Name = 'logins';  Title = 'Yaware Queue (logins)';  Timeout = 180; Sleep = 1 },
    @{ Name = 'default'; Title = 'Yaware Queue (reports)'; Timeout = 700; Sleep = 3 }
)

foreach ($worker in $workers) {
    $running = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match 'queue:work' -and $_.CommandLine -match "--queue=$($worker.Name)" }

    if ($running) {
        Write-Host "Обробник черги '$($worker.Name)' вже працює. Пропускаю." -ForegroundColor Yellow
    } else {
        Start-Process powershell -ArgumentList '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle = '$($worker.Title)'; Set-Location '$root\backend'; php artisan queue:work --queue=$($worker.Name) --timeout=$($worker.Timeout) --tries=1 --sleep=$($worker.Sleep)"
        Write-Host "Обробник черги '$($worker.Name)' запущено" -ForegroundColor Green
    }
}

$viteRunning = Get-CimInstance Win32_Process -Filter "Name = 'node.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match 'vite' -and $_.CommandLine -match [regex]::Escape("$root\frontend") }

if ($viteRunning) {
    Write-Host "Фронтенд вже працює. Пропускаю." -ForegroundColor Yellow
} else {
    # --open відкриє браузер на фактичному порту (5173 або наступному вільному)
    Start-Process powershell -ArgumentList '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle = 'Yaware Frontend'; Set-Location '$root\frontend'; npm run dev -- --open"
    Write-Host "Фронтенд запускається — браузер відкриється автоматично" -ForegroundColor Green
}
