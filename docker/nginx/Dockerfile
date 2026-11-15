# Входная точка: статика из public/assets, остальное — в приложение (Uvicorn), WebSocket диктовки — в ASR. Непривилегированный nginx (порт 8080).
ARG NGINX_IMAGE=nginxinc/nginx-unprivileged:1.28-alpine@sha256:7377697a821c131a924a7105fafbe7414db4e9fcc77a6f08f776f33f141ec3f8
FROM ${NGINX_IMAGE}
COPY docker/nginx/proxy_app.conf /etc/nginx/proxy_app.conf
COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY public/assets /srv/app/public/assets
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=5 CMD wget -q -O /dev/null http://127.0.0.1:8080/nginx-health || exit 1
