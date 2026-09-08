{{-- Лист керівнику про критичні порушення дня.

     Стилістика — та сама, що в SPA (frontend/src/style.css): ground #EDEDED,
     білі площини з межею #f0f4f7, тіловий акцент #149d8d, червоний лише для
     помилок (#c2402f на #faeeec), нульові заокруглення, Inter.

     Верстка навмисно на таблицях і з інлайн-стилями: поштові клієнти ріжуть
     <style> у <head>, не вантажать шрифти й погано розуміють flex/grid. Іконок
     теж немає — Gmail вирізає інлайновий SVG, а зовнішні картинки блокує до
     згоди користувача. --}}
@php
    $typeLabels = [
        'personal_time' => 'Особистий час',
        'no_task_evidence' => 'Таски не підтверджені',
        'schedule' => 'Графік',
        'side_work' => 'Робота на сторону',
        'other' => 'Інше',
    ];

    $font = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif";
@endphp
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Порушення в робочому дні: {{ $employeeName }}</title>
</head>
<body style="margin:0; padding:0; background:#EDEDED;">

{{-- Рядок попереднього перегляду в списку листів; у самому листі не видно. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">
    {{ $employeeName }}, {{ $date }} — {{ count($violations) }}
    {{ count($violations) === 1 ? 'порушення, яке варто зʼясувати' : 'порушення, які варто зʼясувати' }}
</div>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#EDEDED;">
<tr>
<td align="center" style="padding:24px 12px;">

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="640" style="width:100%; max-width:640px;">

        {{-- Шапка — та сама сітка, що .page-head у SPA: квадрат-іконка, назва, підзаголовок --}}
        <tr>
            <td style="background:#ffffff; border:1px solid #f0f4f7; padding:16px 22px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                        <td width="40" valign="top" style="width:40px; padding-right:14px;">
                            <div style="width:40px; height:40px; background:#EDEDED; text-align:center; line-height:40px; font-family:{{ $font }}; font-size:20px; font-weight:800; color:#c2402f;">!</div>
                        </td>
                        <td valign="middle" style="font-family:{{ $font }};">
                            <div style="font-size:16.5px; font-weight:800; color:#149d8d; letter-spacing:-0.01em;">
                                {{ $employeeName }} — {{ $date }}
                            </div>
                            <div style="font-size:13px; color:#8a8a8a; margin-top:2px;">
                                {{ count($violations) === 1 ? 'Критичне порушення в робочому дні' : 'Критичні порушення в робочому дні' }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        @if ($summary)
            <tr><td style="height:12px; line-height:12px; font-size:0;">&nbsp;</td></tr>
            <tr>
                <td style="background:#ffffff; border:1px solid #f0f4f7; padding:14px 16px; font-family:{{ $font }}; font-size:13.5px; line-height:1.55; color:#149d8d;">
                    {{ $summary }}
                </td>
            </tr>
        @endif

        <tr><td style="height:16px; line-height:16px; font-size:0;">&nbsp;</td></tr>
        {{-- Заголовок секції — як .section-head у панелі AI-аналізу: назва і лічильник поруч --}}
        <tr>
            <td style="padding-bottom:10px; font-family:{{ $font }};">
                <span style="font-size:14.5px; font-weight:700; color:#149d8d;">Що потребує уточнення</span>
                <span style="font-size:12px; font-weight:500; color:#8a8a8a; padding-left:10px;">{{ count($violations) }}</span>
            </td>
        </tr>

        @foreach ($violations as $violation)
            <tr>
                {{-- Площина з червоною лінією зліва — як .hint-banner, але в семантиці помилки --}}
                <td style="background:#ffffff; border:1px solid #f0f4f7; border-left:3px solid #c2402f; padding:13px 16px; font-family:{{ $font }};">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="font-family:{{ $font }}; font-size:12px; font-weight:600; color:#c2402f; background:#faeeec; padding:3px 9px; white-space:nowrap;">
                                Критичне
                            </td>
                            <td style="font-family:{{ $font }}; font-size:12px; font-weight:600; color:#8a8a8a; padding-left:10px;">
                                {{ $typeLabels[$violation['type']] ?? 'Інше' }}
                            </td>
                        </tr>
                    </table>

                    <p style="margin:9px 0 0; font-size:13.5px; line-height:1.55; color:#149d8d; font-variant-numeric:tabular-nums;">
                        {{ $violation['details'] }}
                    </p>

                    @if (! empty($violation['evidence']))
                        <p style="margin:6px 0 0; font-size:12.5px; line-height:1.5; color:#8a8a8a; font-variant-numeric:tabular-nums;">
                            Підстава: {{ $violation['evidence'] }}
                        </p>
                    @endif

                    @if (! empty($violation['question']))
                        <p style="margin:10px 0 0; font-size:13px; line-height:1.5; color:#6b6b6b;">
                            <span style="font-weight:700; color:#6b6b6b;">Що запитати:</span> {{ $violation['question'] }}
                        </p>
                    @endif
                </td>
            </tr>
            @if (! $loop->last)
                <tr><td style="height:10px; line-height:10px; font-size:0;">&nbsp;</td></tr>
            @endif
        @endforeach

        <tr><td style="height:18px; line-height:18px; font-size:0;">&nbsp;</td></tr>

        {{-- Кнопка — .btn-accent: квадратна, тілова, з тією ж тінню --}}
        <tr>
            <td>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="background:#149d8d; box-shadow:0 2px 6px rgba(17,17,17,0.18);">
                            <a href="{{ $appUrl }}" style="display:inline-block; padding:12px 22px; font-family:{{ $font }}; font-size:14.5px; font-weight:600; color:#ffffff; text-decoration:none;">
                                Відкрити повний розбір дня
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding-top:16px; font-family:{{ $font }}; font-size:12px; line-height:1.55; color:#6b6b6b;">
                Це висновок AI за даними тайм-трекера, а не готове рішення: спершу варто
                почути пояснення працівника. Щоб не отримувати такі листи, приберіть пошту
                в розділі «Працівники» → «Сповіщення про порушення».
            </td>
        </tr>

    </table>

</td>
</tr>
</table>

</body>
</html>
