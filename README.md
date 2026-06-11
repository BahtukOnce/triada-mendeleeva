# triada-mendeleeva.ru

Сайт клуба спортивной мафии «Триада Менделеева» (РХТУ). Полный план проекта — в [PLAN.md](PLAN.md).

## Контуры

| Контур | Домен | Ветка | Каталог на сервере |
|---|---|---|---|
| Тест | test.triada-mendeleeva.ru | `test` | `~/test.triada-mendeleeva.ru` |
| Бой | triada-mendeleeva.ru | `main` | `~/triada-mendeleeva.ru` (пока старый сайт) |

Схема работы: фичи пушатся в `test` → проверка на тестовом домене → merge в `main` → боевой.

## Структура

```
public_html/   — корень сайта (DocumentRoot)
  assets/      — css, js, картинки
  deploy.php   — GitHub-webhook (git pull + миграции)
  migrate.php  — ручной запуск миграций (?key=deploy_secret)
inc/           — ядро: bootstrap, db, auth, helpers, layout
db/migrations/ — нумерованные .sql, применяются автоматически
config.example.php — шаблон конфига
```

## Установка на сервере (Beget)

1. В панели создать сайт-каталог и прилинковать домен; PHP 8.1+.
2. В пустой каталог сайта: `git clone -b <ветка> https://github.com/BahtukOnce/triada-mendeleeva.git .`
   (панель создаёт `public_html` — удалить её перед клоном).
3. Создать БД в панели → MySQL.
4. Скопировать `config.example.php` → `config.php` (в корне каталога, рядом с `public_html`), заполнить БД, секрет, окружение.
5. Открыть `https://<домен>/migrate.php?key=<deploy_secret>` — применятся миграции.
6. GitHub → Settings → Webhooks: URL `https://<домен>/deploy.php`, content type `application/json`,
   secret = `deploy_secret` из конфига, событие push. После этого каждый пуш в свою ветку деплоится сам.

Первый зарегистрированный пользователь автоматически становится главой клуба.

## Локальная разработка

Кода сборки нет: правки в PyCharm → коммит в `test` → пуш. Секреты в git не попадают (`config.php` в `.gitignore`).
