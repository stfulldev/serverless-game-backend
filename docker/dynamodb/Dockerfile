# syntax=docker/dockerfile:1.7

ARG DYNAMODB_VERSION=3.3.1

FROM amazon/dynamodb-local:${DYNAMODB_VERSION}

USER root

RUN mkdir -p /home/dynamodblocal/data \
    && chown -R dynamodblocal:dynamodblocal /home/dynamodblocal/data

USER dynamodblocal
