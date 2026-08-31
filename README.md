# Serverless Game Backend

Serverless-бэкенд для Unity-игры на Laravel 13, Bref и AWS. Основное хранилище проекта — DynamoDB; локальная разработка полностью изолирована в Docker.

## Архитектура

![Архитектура Serverless Game Backend](docs/architecture/architecture_serverless_game_diagram_v1.png)

## Быстрый старт

```bash
make init
```

Команда создаст `.env`, соберёт образы, установит Composer/npm-зависимости в Docker volumes, сгенерирует `APP_KEY`, запустит сервисы и подготовит локальные DynamoDB-таблицы.

После запуска доступны:

- Laravel API: <http://localhost:8000>;
- Vite asset-server: `http://localhost:5173`;
- DynamoDB Admin: <http://localhost:8002>;
- DynamoDB Local с хоста: <http://localhost:8001>;
- DynamoDB Local из контейнеров: `http://dynamodb:8000`.

Порты можно изменить через `APP_PORT`, `VITE_PORT`, `DYNAMODB_PORT` и `DYNAMODB_ADMIN_PORT` в `.env`.

## Сервисы

| Сервис | Назначение |
| --- | --- |
| `app` | PHP 8.4, Composer, Laravel HTTP server и Artisan |
| `node` | Node.js, npm и Vite с HMR |
| `dynamodb` | DynamoDB Local с постоянным именованным volume |
| `dynamodb-admin` | Веб-интерфейс для просмотра и редактирования локальной DynamoDB |

## Локальная DynamoDB

`make dynamodb-setup` идемпотентно создаёт восемь физических таблиц: `players`, `wallets`, `buildings`, `productions`, `occupied_cells`, `cleared_obstacles`, `commands` и `outbox_events`. Префикс локальных таблиц — `serverless-game-backend-local-`.

После `make up` таблицы и записи доступны в DynamoDB Admin по адресу <http://localhost:8002>. Локальные AWS-профили и credentials для этого не нужны.

Для ручной проверки можно создать и прочитать тестового игрока:

```bash
make artisan ARGS="player:setup-local demo-player v1 demo-seed 1000"
make artisan ARGS="player:show-local demo-player"
```

Расчистить препятствие через HTTP API можно из Postman или `curl`:

```bash
curl --request POST http://localhost:8000/api/v1/obstacles/tree-001/clear \
  --header 'Accept: application/json' \
  --header 'X-Player-Id: demo-player' \
  --header 'Idempotency-Key: aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
```

Команда атомарно списывает монеты и создаёт записи в `cleared_obstacles`, `commands` и `outbox_events`. Повтор с тем же `Idempotency-Key` возвращает сохранённый ответ без повторного списания.

## Make-команды

```bash
make help
make up
make dynamodb-setup
make down
make restart
make ps
make logs
make shell
make test
make format
make assets
make quality
```

Команды с аргументами:

```bash
make artisan ARGS="route:list"
make composer ARGS="show --direct"
make npm ARGS="outdated"
```

После изменения `composer.lock` или `package-lock.json` выполните `make install`. Для полной пересборки образов без cache используйте `make rebuild`.

`make down` сохраняет зависимости и данные DynamoDB. `make destroy` удаляет все volumes проекта и требует явного подтверждения.

## Проверка проекта

```bash
make quality
```

Команда валидирует и проверяет Composer/npm-зависимости, запускает PHPUnit и собирает frontend assets.
