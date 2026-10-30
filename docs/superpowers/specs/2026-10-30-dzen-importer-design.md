# Dzen Importer — дизайн

## Зачем

Канал `https://dzen.ru/radar-c.ru` (аудиторская компания «Радар-Консалтинг») публикует статьи на Дзене.
Нужно, чтобы новые статьи автоматически появлялись на своём WordPress-сайте: полным текстом, с картинками,
нормальным слагом и SEO-полями. Цель — увести читателя с Дзена на собственный сайт.

## Что делает плагин

1. Админ указывает канал и настройки в мастере при первом запуске.
2. Плагин фиксирует текущее состояние канала: запоминает ID всех существующих публикаций, ничего не импортируя.
3. Дальше по расписанию (по умолчанию раз в 10 минут) проверяет канал и импортирует только новые публикации.
4. Каждая публикация: полный текст, заголовки, списки, картинки (в медиатеку), обложка в миниатюру,
   оригинальная дата, слаг, автор, рубрика, SEO-поля выбранного SEO-плагина.
5. Статьи, вышедшие до установки, догружаются вручную, порциями.

## Источник данных (проверено вживую 2026-10-30)

- **RSS у Дзена нет.** `https://dzen.ru/rss/<канал>` и страницы канала отдают пустую JS-заглушку.
- **JSON-фид:** `https://dzen.ru/api/v3/launcher/export?channel_name=<канал>` → 20 последних публикаций.
  Поля: `publication_object_id`, `title`, `text` (анонс ~500–600 символов), `image`, `share_link`,
  `publication_date` (unix), `item_type`, `views`.
  Листается вглубь: `more.link` → `/api/v3/launcher/channel-more?...next_page_id=...` (по 20).
  Фид отвечает и обычному браузерному UA, и Googlebot. Для листания нужен обычный браузерный UA.
- **Полный текст:** страница `https://dzen.ru/a/<id>` отдаёт SSR-HTML только «известным краулерам»
  по User-Agent (Googlebot, YandexBot, bingbot, Twitterbot, facebookexternalhit, TelegramBot, WhatsApp).
  С любым другим UA — пустая заглушка с SSO-редиректом. Проверено: самодельный `*Bot/1.0` не проходит.
  Плагин ходит с UA `Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)`.
- **Структура статьи:** `[itemprop="articleBody"]` (он же `[data-testid="article-body"]`), внутри блоки
  `[data-testid="article-render__block"]`: `<p>`, `<h2>`, `<ul>/<ol>/<li>`, `<figure><img><figcaption>`,
  `<a>`, `<b>`. Заголовок — `h1[data-testid="article-title"]`. Обложка — `meta[property="og:image"]`.

Риск: Дзен может закрыть доступ краулерам. Плагин не падает: пишет ошибку в лог и показывает статус в админке.

## Границы

**Делаем:** импорт текстовых статей (лонгриды и заметки), картинок, обложек; настройки; крон; ручную догрузку;
SEO-интеграции; лог.

**Не делаем:** видео и шортсы (не переносятся текстом — пропускаем и пишем в лог); комментарии; статистику;
обратный экспорт на Дзен; мультисайт-специфику.

## Решения (мои, если не сказано иначе)

- Интервал по умолчанию 10 минут, настраивается, минимум 5.
- Первый запуск: базовая линия = все ID с первой страницы фида + максимальная дата публикации.
  Всё, что старше этой даты, автоматически не импортируется никогда.
- Дубликаты: post meta `_dzen_publication_id` (источник истины). Дополнительно `_dzen_source_url`.
- Публикация: сразу `publish` (настраивается на `draft`).
- Ссылка на источник: блок в конце записи, по умолчанию включена, `rel="nofollow noopener"`, текст настраивается.
- Картинки скачиваются в медиатеку. Если скачать не удалось — остаётся внешний URL, в лог предупреждение.
- Alt картинки: из Дзена, иначе заголовок статьи.
- Слаг: транслитерация кириллицы + `sanitize_title`.
- Категория: выбирается в настройках (по умолчанию «Без рубрики»). Метки не создаём.
- Автор: выбирается из пользователей сайта (по умолчанию администратор, активировавший плагин).
- «Сеошит» = SEO-заголовок, meta description (из первых ~160 символов), ключевая фраза (заголовок),
  slug, миниатюра, дата. Если SEO-плагина нет — плагин сам выводит `meta description` и OG-теги.
- Мастер настройки — это страница настроек с блоком «Первый запуск»; после активации редирект на неё.

## SEO-интеграции

Выбор в настройках: `auto` | `yoast` | `rankmath` | `aioseo` | `seopress` | `slimseo` | `none`.

| Плагин | Title | Description | Focus keyword |
|---|---|---|---|
| Yoast | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` | `_yoast_wpseo_focuskw` |
| Rank Math | `rank_math_title` | `rank_math_description` | `rank_math_focus_keyword` |
| AIOSEO | `_aioseo_title` | `_aioseo_description` | — |
| SEOPress | `_seopress_titles_title` | `_seopress_titles_desc` | — |
| Slim SEO | `slim_seo` (array: title, description) | ← | — |
| none | свои `meta description` + OG на `wp_head` | | |

Автоопределение: `defined('WPSEO_VERSION')`, `class_exists('RankMath')`, `defined('AIOSEO_VERSION')`,
`defined('SEOPRESS_VERSION')`, `defined('SLIM_SEO_VERSION')`. В настройках показывается, что найдено.

## Данные

- `dzen_importer_settings` (option): `channel`, `interval`, `post_status`, `author_id`, `category_id`,
  `source_link`, `source_link_nofollow`, `source_text`, `seo_plugin`, `max_per_run`, `backfill_batch`.
- `dzen_importer_state` (option): `baseline_done`, `baseline_ts`, `last_seen_ts`, `last_run`,
  `last_error`, `backfill_cursor` (url + offset), `imported_total`.
- `dzen_importer_log` (option, autoload=no): кольцевой буфер 300 записей `{time, level, message}`.
- Post meta: `_dzen_publication_id`, `_dzen_source_url`, `_dzen_imported_at`.

## Алгоритм импорта

```
run():
  if lock: return
  feed = fetch(page 1)
  if !baseline_done: save ids+max_ts; baseline_done=1; return
  new = items where ts > last_seen_ts && id not imported   (не больше max_per_run)
  if page полный и самый старый item новее last_seen_ts: листать дальше (до 5 страниц)
  for item in new: import(item); usleep(1s)
  last_seen_ts = max(last_seen_ts, max ts среди просмотренных)
```

Импорт одной статьи:
1. Ранний выход, если `_dzen_publication_id` уже есть (повторный запуск, гонка).
2. GET страницы статьи (Googlebot UA, 30 с).
3. Парсинг: заголовок, блоки, картинки, дата.
4. Скачивание картинок, замена src на локальные.
5. Сборка `post_content` через `wp_kses` с белым списком.
6. `wp_insert_post` + миниатюра + meta + SEO.
7. Ошибка на любом шаге → лог, статья не создаётся (никаких полупустых записей), `last_seen_ts` не двигается
   дальше этой статьи, чтобы повторить в следующий раз.

## Крон

`wp_schedule_event(time()+60, 'dzen_importer_interval', 'dzen_importer_run')` с кастомным интервалом.
Блокировка от наложения — transient `dzen_importer_lock` на 5 минут.
При смене интервала — пересоздание расписания. В админке подсказка про системный cron
(`wp cron event run dzen_importer_run`) для сайтов без трафика.

Плюс WP-CLI: `wp dzen status`, `wp dzen sync`, `wp dzen backfill --limit=10`.

## Админка

Страница «Настройки → Импорт из Дзена»:
- Канал (URL или слаг) + кнопка «Проверить канал» → название канала и число публикаций на странице.
- Расписание: интервал, статус крона, следующий запуск.
- Публикация: статус, автор, рубрика, ссылка на источник (вкл/выкл, nofollow, текст).
- SEO: выбор плагина, статус автоопределения, превью сгенерированных title/description.
- Первый запуск: «зафиксировано N публикаций, новых будет импортировать с <дата>».
- Кнопки: «Синхронизировать сейчас», «Догрузить старые» (порция).
- Лог (последние 50) и список импортированных записей.

## Безопасность и качество

- `manage_options` + nonce на всех действиях, `sanitize_*` на входе, `esc_*` на выводе.
- Никаких прямых запросов к БД, только WP API.
- Текст статьи проходит `wp_kses` с белым списком; атрибуты `style`, `class`, `data-*` вырезаются.
- Плагин не удаляет записи при деинсталляции; удаляет только свои опции.
- README: импортировать можно только свой контент.

## Тестирование

- PHPUnit (без WordPress) для парсера, клиента фида, слагов, описаний, настроек — на фикстурах.
- Интеграция: WordPress в Docker (`wordpress:php8.2-apache` + `mariadb`), плагин монтируется,
  активируется через wp-cli, запускается импорт реальной статьи, проверяются запись, медиа, meta.
- CI: GitHub Actions — `php -l`, PHPUnit на 7.4/8.1/8.3, phpcs (WordPress Coding Standards, мягко).
