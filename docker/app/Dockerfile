# Веб-приложение (FastAPI + Uvicorn) и планировщик — один образ. Код в образе, данные — в томе APP_VAR_DIR,
# секреты — в окружении (.env вне образа).
ARG PYTHON_IMAGE=python:3.13-slim-bookworm@sha256:a5a7017efc5969e00ae2b91c8eb0631049a569652aa4ffc4501769049ae45c72
FROM ${PYTHON_IMAGE}

# poppler-utils и tesseract (rus+eng) — извлечение текста PDF и OCR сканов; catdoc — старый формат Word (.doc).
RUN set -eux; \
    apt-get -o Acquire::Retries=5 update; \
    apt-get -o Acquire::Retries=5 install -y --no-install-recommends poppler-utils catdoc tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng ca-certificates; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /srv/app
COPY requirements.txt /srv/app/requirements.txt
RUN pip install --no-cache-dir --disable-pip-version-check -r requirements.txt

COPY llmp /srv/app/llmp
COPY public/assets /srv/app/public/assets
COPY VERSION /srv/app/VERSION
RUN python -m compileall -q /srv/app/llmp; mkdir -p /data/app && chown www-data:www-data /data/app

ENV APP_VAR_DIR=/data/app PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
# www-data (uid 33) — тот же владелец каталога данных, что у прежней PHP-версии: обновление без смены прав
USER www-data
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD ["python", "-c", "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/healthz', timeout=4).status == 200 else 1)"]
# --proxy-headers: адрес клиента и схема от nginx этого же проекта; доверие к внешнему прокси — настройка «Система».
CMD ["sh", "-c", "exec uvicorn llmp.web.main:app --host 0.0.0.0 --port 8000 --workers ${APP_WORKERS:-4} --proxy-headers --forwarded-allow-ips='*' --no-server-header"]

ARG APP_VERSION=0.0.0
ARG APP_GIT_COMMIT=unknown
ENV APP_VERSION=${APP_VERSION} APP_GIT_COMMIT=${APP_GIT_COMMIT}
