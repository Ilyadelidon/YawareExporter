# Design

Візуальна система фронтенду (Vue 3 + PrimeVue, стилі в `frontend/src/style.css` + scoped-стилі view-файлів). Джерело істини — код; цей файл фіксує систему для консистентних змін.

## Theme

Світла, «канцелярська» панель: сірий фон застосунку, білі пласкі панелі, один тіловий акцент. Жодних заокруглень: глобально `border-radius: 0 !important` (єдиний виняток — круглі крапки-індикатори статусу, `50% !important`).

## Color palette

CSS-змінні в `:root`:

- `--app-bg: #EDEDED` — фон застосунку
- `--surface: #ffffff` — панелі
- `--line: #f0f4f7` — межі, розділювачі, фон «pill»-полів і бейджів
- `--accent: #149d8d` (teal) — і бренд-акцент, і `--ink` (заголовки); hover-стан `#118779`
- `--text-dim: #6b6b6b`, `--muted: #8a8a8a`, `--muted-2: #a8a8a8` — три рівні сірого тексту

Семантика продуктивності (History/бейджі):

- Продуктивно: текст `#149d8d` / `#0e7d70`, фон бейджа `#d5f2ee`
- Непродуктивно: текст `#d05353` / `#b33c3c`, фон бейджа `#fbe3e3`
- Нейтрально: `#8a8f98`, фон `--line`
- Помилки/запізнення: `#c2402f` на `#faeeec` (status-badge.is-failed), `#d05353` (значення запізнень)

Акцент несе дію (кнопки), поточний стан і семантику «продуктивно». Не використовується як декор.

## Typography

- Єдина сімʼя: `'Inter', system-ui, Avenir, Helvetica, Arial, sans-serif`; моно `'IBM Plex Mono'` лише для технічних деталей помилок (`pre.error-details`).
- Дрібна, щільна шкала: значення 20px/700, заголовки сторінки 16.5px/800 (letter-spacing -0.01em), заголовки секцій (h2 у column-head) 14.5px/700, тіло таблиць 13–13.5px, підписи/лейбли 11–12.5px/600, часто UPPERCASE з letter-spacing 0.04em.
- Числа й час: `font-variant-numeric: tabular-nums`, вирівнювання праворуч у колонках часу; формат тривалості `Г:ХХ` або `Г:ХХ:СС`, порожнє значення — «—».

## Elevation & panels

- `.panel`: surface + 1px `--line` + `--shadow-sm` (0 1px 2px rgba(17,17,17,.04)).
- `.page-head`: та сама площина з `--shadow-card`; сильніша тінь `0 2px 6px rgba(17,17,17,.18)` лише під акцентними кнопками.
- Пласкі площини, без вкладених карток.

## Components (усталений словник)

- `.page-head` — шапка сторінки: іконка 40px на `--app-bg`, title/subtitle, справа `.page-head-actions` (Select працівника, `.field-pill` з DatePicker, акцентна кнопка).
- `.btn-accent` / `.gen-btn` — акцентні кнопки; disabled = `--text-dim`, у процесі — спінер (SVG, `animation: spin .8s linear`).
- `.status-badge` — бейдж стану з крапкою (пульсує при processing).
- `.hint-banner` — підказки замість PrimeVue Message (legacy-патерн має 3px смугу зліва; у нових елементах смуги не додавати).
- `.total-card` / `.time-card` — сітка компактних метрик: label 11px caps + значення 16.5–20px.
- `.productivity-badge` — текстовий бейдж продуктивності (колір + текст, не лише колір).
- `.skeleton` — завантаження контенту (shimmer), замість спінерів посеред сторінки.
- `.empty-state` — порожні стани: пунктирна межа, іконка, title + пояснення, що робити.
- PrimeVue: DataTable (th 12px/600 muted, td 13.5px, межі `--line`, expander-рядки), DatePicker/Select у «pill»-обгортках, Message для помилок API.

## Layout

- Сторінка: `.page-head` зверху, контент нижче з `margin-top: 14–16px`; двоколонкова `results-grid` 1.15fr/1fr (колапс в 1 колонку до 900px); сітки метрик `repeat(auto-fit, minmax(140px, 1fr))`.
- Щільність таблиць: padding клітинок 6–8px 10–16px.

## Motion

- `fadeUp 0.35s ease both` на появу секцій контенту; `pulseDot 1.4s` для активних станів; `spin 0.8s` для спінерів; transitions 0.15s ease на кнопках. Іншої анімації немає.
