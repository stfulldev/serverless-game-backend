# syntax=docker/dockerfile:1.7

ARG NODE_VERSION=24.19.0

FROM node:${NODE_VERSION}-bookworm-slim AS development

ARG APP_UID=1000
ARG APP_GID=1000

RUN set -eux; \
    if [ "$(id -g node)" != "${APP_GID}" ]; then groupmod --gid "${APP_GID}" node; fi; \
    if [ "$(id -u node)" != "${APP_UID}" ]; then usermod --uid "${APP_UID}" --gid "${APP_GID}" node; fi; \
    mkdir -p /app/node_modules; \
    chown -R node:node /app /home/node

WORKDIR /app

USER node

EXPOSE 5173

CMD ["npm", "run", "dev"]
