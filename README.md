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

## Локальная работа

`make init` уже создаёт таблицы DynamoDB. Посмотреть данные можно через [DynamoDB Admin](http://localhost:8002).

Создать тестового игрока:

```bash
make artisan ARGS="player:setup-local demo-player v1 demo-seed 1000"
```

Проверить его состояние:

```bash
make artisan ARGS="player:show-local demo-player"
```

## Postman

В [Postman Collection](docs/postman/serverless-game-backend.postman_collection.json) собран полный сценарий: расчистка препятствия, постройка и перемещение грядки, производство, сбор урожая и удаление здания.

Для запуска создайте отдельного игрока:

```bash
make artisan ARGS="player:setup-local postman-demo-player v1 postman-demo-seed 1000"
```

Импортируйте коллекцию и запустите её через Collection Runner. Для повторного прогона укажите новый `playerId` и создайте игрока с таким же ID.

## AWS

Инфраструктура описана в `infrastructure/cdk`. В AWS используются Cognito, Lambda, API Gateway, DynamoDB, EventBridge, SQS и CloudWatch. Локально работают Laravel API и DynamoDB; остальные AWS-сервисы покрыты тестами инфраструктуры.

```bash
make cdk-test
make cdk-synth ENVIRONMENT=dev
```

## Команды

```bash
make help       # все команды
make up         # запустить контейнеры
make down       # остановить контейнеры
make logs       # смотреть логи
make shell      # открыть shell контейнера app
make test       # запустить PHPUnit
make quality    # выполнить все проверки
```

`make down` сохраняет данные. `make destroy` удаляет контейнеры и volumes проекта.
