# Dzen Importer — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** WordPress-плагин, который по расписанию переносит новые статьи из Дзен-канала на сайт с полным текстом, картинками и SEO-полями.

**Architecture:** Плагин без внешних зависимостей, PHP 7.4+. Поток: `FeedClient` (JSON-фид) → `Importer` → `ArticleParser` (SSR-HTML под Googlebot UA) → `Media` (скачивание картинок) → `Seo` (адаптеры плагинов). Состояние в options, дедупликация по post meta. Крон с блокировкой.

**Tech Stack:** PHP 7.4+, WordPress API (`wp_remote_get`, `wp_insert_post`, `media_handle_sideload`), PHPUnit 9, Docker (интеграция), GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-30-dzen-importer-design.md`

## Global Constraints

- PHP 7.4+ (без typed properties, без `match`, без `str_contains`).
- Никаких composer-зависимостей в рантайме плагина.
- Префикс всего: `dzen_importer_` / `DzenImporter\` / meta `_dzen_`.
- Весь ввод — `sanitize_*`, весь вывод — `esc_*`, действия — nonce + `manage_options`.
- Текст статьи — только через `wp_kses` с белым списком из спеки.
- Сетевые вызовы: таймаут 20 с (фид) / 30 с (статья), ошибки → лог, не исключения.
- UA для страниц статей: `Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)`.
- UA для JSON-фида: обычный браузерный (иначе не листается).

## Review Focus

- Статья, у которой Дзен отдал заглушку вместо HTML → запись не создаётся, в логе понятная ошибка, повтор в следующий запуск.
- Повторный запуск после сбоя → нет дублей и нет потери статей.
- Заголовок с кириллицей, кавычками, эмодзи → валидный слаг, нет пустого слага.
- Картинка не скачалась (404, таймаут) → статья всё равно импортируется с внешним URL.
- Пустой/битый фид (Дзен отдал HTML вместо JSON) → ошибка в лог, состояние не портится.

---

### Task 1: Каркас плагина и настройки

**Files:** `dzen-importer.php`, `includes/class-settings.php`, `includes/class-plugin.php`, `uninstall.php`, `tests/bootstrap.php`, `phpunit.xml`, `composer.json` (dev only)

**Interfaces:**
- `DzenImporter\Settings::defaults(): array`
- `DzenImporter\Settings::get(): array` (merged with defaults)
- `DzenImporter\Settings::update(array $input): array` (sanitized)
- `DzenImporter\Settings::channel_slug(string $raw): string` — из URL/`@name`/слага достаёт слаг канала

- [ ] Тест: `channel_slug('https://dzen.ru/radar-c.ru?x=1') === 'radar-c.ru'`, `'@radar-c.ru'`, `'radar-c.ru/'`
- [ ] Тест: `update()` приводит interval к >= 5, post_status к `publish|draft`, seo_plugin к белому списку
- [ ] Реализация, активация: дефолтные опции, редирект на страницу настроек
- [ ] `php -l`, PHPUnit — зелёные

### Task 2: Логгер

**Files:** `includes/class-logger.php`

**Interfaces:** `Logger::log(string $level, string $message): void`, `Logger::all(): array`, `Logger::clear(): void`

- [ ] Тест: буфер не растёт выше 300, свежие записи сверху
- [ ] Реализация (option, autoload=false)

### Task 3: Клиент фида

**Files:** `includes/class-feed-client.php`, `tests/fixtures/feed-page1.json`

**Interfaces:**
- `FeedClient::__construct(callable $http = null)`
- `FeedClient::page(string $channel, ?string $moreUrl = null): array{items: array, more: ?string}` — нормализованные items: `id,title,teaser,url,image,ts`
- Бросает `FeedException` при не-JSON/ошибке HTTP

- [ ] Тест на фикстуре: 20 items, поля нормализованы, `more` извлечён
- [ ] Тест: HTML вместо JSON → `FeedException`
- [ ] Тест: URL содержит `channel_name` и `lang=ru`
- [ ] Реализация

### Task 4: Парсер статьи

**Files:** `includes/class-article-parser.php`, `tests/fixtures/article.html` (реальная сохранённая страница, урезанная)

**Interfaces:** `ArticleParser::parse(string $html): array{title, html, images: string[], description}`

- [ ] Тест: заголовок из `h1[data-testid=article-title]`
- [ ] Тест: `<p>`, `<h2>`, `<ul><li>` сохраняются; `class`, `style`, `data-*`, `<button>`, `<svg>` вырезаются
- [ ] Тест: картинки из `figure img` попадают в `images`, порядок сохранён
- [ ] Тест: мусорные блоки (`data-testid="d2d-card"`, `card-*`) в тело не попадают
- [ ] Тест: страница-заглушка (нет `articleBody`) → пустой html, `title` пустой
- [ ] Реализация на `DOMDocument` + `libxml_use_internal_errors`
- [ ] Отдельный тест: `description` ≤ 160 символов, обрезка по слову, без HTML

### Task 5: Слаг и текст

**Files:** `includes/class-text.php`

**Interfaces:** `Text::slug(string $title, string $fallback = ''): string`, `Text::description(string $html, int $limit = 160): string`

- [ ] Тест: «Аудит учёта запасов по ФСБУ 5/2019» → `audit-ucheta-zapasov-po-fsbu-5-2019`
- [ ] Тест: эмодзи/кавычки/двойные пробелы → валидный слаг, не пустой
- [ ] Тест: description вырезает теги, схлопывает пробелы
- [ ] Реализация (таблица транслитерации)

### Task 6: Медиа

**Files:** `includes/class-media.php`

**Interfaces:** `Media::import(string $url, int $postId, string $alt, string $caption = ''): ?int` (attachment ID)

- [ ] Тест (unit, с подменённым HTTP): неудачная загрузка → `null`, без исключения
- [ ] Реализация: `download_url` + `media_handle_sideload`, alt/caption, дедупликация по `_dzen_source_image`
- [ ] Интеграционная проверка в Docker: файл появляется в uploads, есть `_wp_attachment_image_alt`

### Task 7: SEO-адаптеры

**Files:** `includes/class-seo.php`

**Interfaces:** `Seo::detect(): string`, `Seo::apply(int $postId, array $data): void`, `Seo::render_fallback_meta(): void`

- [ ] Тест: detect по константам/классам (подменяемые)
- [ ] Тест: для каждого плагина пишутся ровно свои meta-ключи из спеки
- [ ] Тест: `none` → вывод `meta description` + `og:*` на `wp_head`, ровно один раз
- [ ] Реализация

### Task 8: Импортёр

**Files:** `includes/class-importer.php`

**Interfaces:**
- `Importer::import_item(array $item, array $settings): int|WP_Error`
- `Importer::already_imported(string $publicationId): bool`
- `Importer::build_content(array $parsed, array $settings): string`

- [ ] Тест: `already_imported` по meta `_dzen_publication_id`
- [ ] Тест: `build_content` добавляет блок источника, если включён, и не добавляет, если выключен
- [ ] Тест: `build_content` с nofollow → `rel="nofollow noopener"`
- [ ] Тест: дата записи = оригинальная, статус/автор/рубрика из настроек (WP integration)
- [ ] Реализация

### Task 9: Синхронизация и крон

**Files:** `includes/class-sync.php`, `includes/class-cron.php`

**Interfaces:** `Sync::run(): array{imported:int, skipped:int, errors:int}`, `Cron::schedule()`, `Cron::unschedule()`

- [ ] Тест: первая синхронизация ничего не импортирует и ставит baseline
- [ ] Тест: вторая — импортирует только новее `last_seen_ts`
- [ ] Тест: ошибка импорта не двигает `last_seen_ts` дальше упавшей статьи
- [ ] Тест: замок не даёт двум запускам идти одновременно
- [ ] Тест: пагинация при >20 новых (мок фида из 2 страниц)
- [ ] Реализация

### Task 10: Админка

**Files:** `includes/class-admin.php`, `assets/admin.css`

- [ ] Рендер страницы: все поля, nonce, текущий статус, лог, список импортированного
- [ ] Обработчики: сохранить, проверить канал, синхронизировать сейчас, догрузить старые, очистить лог
- [ ] Проверка прав и nonce на каждом обработчике (unit + ручная проверка в Docker)

### Task 11: WP-CLI

**Files:** `includes/class-cli.php`

- [ ] `wp dzen status`, `wp dzen sync`, `wp dzen backfill --limit=N`

### Task 12: Интеграция и проверка на живом

**Files:** `docker-compose.yml`, `.github/workflows/ci.yml`, `README.md`, `readme.txt`

- [ ] Поднять WP в Docker, активировать, настроить канал, выполнить `wp dzen sync` на реальном Дзене
- [ ] Проверить: запись создана, текст полный (сравнить длину с оригиналом), картинки локальные, миниатюра, SEO-поля, слаг
- [ ] Повторный запуск → 0 новых, дублей нет
- [ ] CI зелёный, репозиторий создан в `sarnas-it` (public), запушен
