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

Разместить тестовую грядку размером 2×2 и стоимостью 200 монет:

```bash
curl --request POST http://localhost:8000/api/v1/buildings \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header 'X-Player-Id: demo-player' \
  --header 'Idempotency-Key: bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' \
  --data '{"building_type":"garden-bed","x":0,"y":0}'
```

Размещение одной транзакцией списывает монеты, создаёт запись в `buildings`, резервирует четыре записи в `occupied_cells` и сохраняет `commands`/`outbox_events`. Пересечение с другим зданием или нерасчищенным препятствием возвращает `409 CELLS_OCCUPIED` без частичных изменений.

Переместить созданное здание, подставив `id` из ответа размещения:

```bash
curl --request PATCH http://localhost:8000/api/v1/buildings/BUILDING_ID/move \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header 'X-Player-Id: demo-player' \
  --header 'Idempotency-Key: cccccccc-cccc-4ccc-8ccc-cccccccccccc' \
  --data '{"x":1,"y":0}'
```

Перемещение атомарно освобождает только покинутые клетки, сохраняет общую часть footprint, резервирует только новые клетки, увеличивает версию здания и записывает `BuildingMoved.v1`. Чужой `buildingId` возвращает `404 BUILDING_NOT_FOUND` без раскрытия владельца.

Запустить производство пшеницы на существующей грядке:

```bash
curl --request POST http://localhost:8000/api/v1/buildings/BUILDING_ID/productions \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --header 'X-Player-Id: demo-player' \
  --header 'Idempotency-Key: eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' \
  --data '{"recipe":"wheat"}'
```

Рецепт `wheat` доступен для `garden-bed`, длится 60 секунд и после будущего сбора даст одну единицу `wheat`. Запуск атомарно помечает здание активным, создаёт `productions`, сохраняет `StartProduction` и записывает `ProductionStarted.v1`. Второе одновременное производство возвращает `409 BUILDING_HAS_ACTIVE_PRODUCTION`.

Удалить здание и освободить занятые им клетки:

```bash
curl --request DELETE http://localhost:8000/api/v1/buildings/BUILDING_ID \
  --header 'Accept: application/json' \
  --header 'X-Player-Id: demo-player' \
  --header 'Idempotency-Key: dddddddd-dddd-4ddd-8ddd-dddddddddddd'
```

Удаление одной транзакцией удаляет запись из `buildings`, освобождает принадлежащие зданию `occupied_cells` и записывает `DeleteBuilding`/`BuildingDeleted.v1` в `commands` и `outbox_events`. В текущей версии стоимость здания не возвращается, а здание с активным производством удалить нельзя. Повтор с тем же `Idempotency-Key` вернёт сохранённый успешный ответ, даже если здания уже нет.

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
