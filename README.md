# triada-mendeleeva.ru

Сайт клуба спортивной мафии «Триада Менделеева» (РХТУ им. Д. И. Менделеева): игровые вечера,
турниры, клубный рейтинг и ELO, статистика игроков, Зал славы, новости, заявки на вступление,
Telegram-бот и Android-приложение.

* [CLAUDE.md](CLAUDE.md) — **как безопасно менять код**, решения владельца и грабли. Читать первым.
* [PLAN.md](PLAN.md) — исходный замысел и статус этапов.

## Контуры

| Контур | Домен | Ветка | Состояние |
|---|---|---|---|
| Бой | triada-mendeleeva.ru | `main` | живой сайт, деплой вебхуком при каждом пуше |
| Тест | test.triada-mendeleeva.ru | `test` | существует, но не используется (вебхук отдаёт 404) |

Фактическая схема работы: правки **в ветку** → зелёные CI-гейты → `merge --ff-only` в `main` → пуш
деплоится на прод сам. Ветка на прод не уезжает: `deploy.php` игнорирует все ref, кроме
`deploy_branch`. Порядок и причины — в [CLAUDE.md](CLAUDE.md).

## Структура

```
public_html/            — DocumentRoot: 40 страниц
  admin/                — админка (19 страниц: вечера, протоколы, турниры, заявки, права ролей…)
  app/                  — раздача Android-приложения: download.php, version.php (манифест обновлений)
  assets/               — css/style.css, js/app.js, js/app-native.js, img, media
  deploy.php            — GitHub-webhook: git reset --hard + миграции
  migrate.php           — ручной прогон миграций (?key=deploy_secret)
  bot.php               — вебхук Telegram-бота (fail-closed: без bot_secret отвечает 503)
  cron_day_reminder.php — напоминание о вечере (крон)
  import_news.php       — импорт постов из Telegram-канала (крон, */30)
  import_applications.php — импорт заявок из старой Google-формы (крон, 0 */6)
inc/                    — ядро (14 модулей): bootstrap, db, auth, helpers, layout,
                          rating, elo, player_stats, bot_lib, import, legacy_import, xlsx
db/migrations/          — 77 нумерованных .sql, применяются автоматически при деплое
mobile/                 — Android-приложение (Capacitor-обёртка над сайтом) + KEYSTORE.md
.github/workflows/      — php-lint (php -l + node --check), build-apk (compile-check), release-app
config.example.php      — шаблон конфига
```

`config.php` и каталог `storage/` (логи ошибок, кэш манифеста приложения, легаси-дампы) лежат в
корне репозитория — уровнем **выше** `public_html` — и в git не попадают.

## Установка на сервере (Beget)

1. В панели создать сайт-каталог, прилинковать домен, PHP 8.2.
2. В пустой каталог: `git clone -b main https://github.com/BahtukOnce/triada-mendeleeva.git .`
   (панель создаёт свою `public_html` — удалить перед клоном).
3. Создать БД в панели → MySQL.
4. `config.example.php` → `config.php` рядом с `public_html`; заполнить доступы к БД, `deploy_secret`,
   `bot_token`, `bot_secret`, `env`.
5. Открыть `https://<домен>/migrate.php?key=<deploy_secret>` — применятся миграции.
6. GitHub → Settings → Webhooks: URL `https://<домен>/deploy.php`, content type `application/json`,
   secret = `deploy_secret`, событие push.
7. Кроны в панели Beget:
   * `*/30 * * * *` → `php public_html/import_news.php` — новости из Telegram-канала;
   * `0 */6 * * *` → `php public_html/import_applications.php` — заявки из старой Google-формы;
   * напоминание о вечере — `php public_html/cron_day_reminder.php`.

Аккаунты создаются приёмом заявки: человек заполняет `/join.php` (пароль задаёт сразу), руководитель
жмёт «Принять заявку» — аккаунт готов. Роли: `player` → `judge` → `admin` → `deputy` → `owner`;
подробные права настраиваются руководителем в таблице прав (`/admin/users.php`).

## Разработка

Сборки нет — правки сразу в файлы. Но **локально проверить нечем**: на машине владельца нет `php`,
`node` и Android SDK. Поэтому синтаксис проверяют CI-гейты, а правки идут через ветку — иначе
опечатка в `inc/bootstrap.php` уедет на прод и положит сайт целиком.

```bash
git checkout -b fix/что-делаем     # правки PHP/JS/Java — только в ветке
git push -u origin fix/что-делаем  # ждём php-lint и compile-check
git checkout main && git merge --ff-only fix/что-делаем && git push
```

Над репозиторием работает второй разработчик — перед пушем всегда
`git fetch origin main && git pull --ff-only`.

Смоук-тест после деплоя:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://triada-mendeleeva.ru/
curl -s -o /dev/null -w "%{http_code}\n" https://triada-mendeleeva.ru/cabinet.php   # 302 для гостя
curl -s -X POST -d '{}' -o /dev/null -w "%{http_code}\n" https://triada-mendeleeva.ru/bot.php  # 403
```

## Android-приложение

`mobile/` — Capacitor-обёртка над сайтом: своя иконка, полноэкранный режим, нижняя навигация,
обновление внутри приложения (`app/version.php` → GitHub Releases). Сборка — workflow
«Релиз приложения» (Actions → Run workflow → версия).

⚠️ Ключ подписи APK когда-то попал в публичный репозиторий; CI переведён на GitHub Secrets,
**ротация ключа за владельцем** — инструкция в [mobile/KEYSTORE.md](mobile/KEYSTORE.md). Репозиторий
приватным делать нельзя: раздача APK и проверка обновлений завязаны на публичные ссылки Releases.
