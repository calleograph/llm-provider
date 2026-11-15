# Внутренний сервис обезличивания (Python). Базовый образ — тот же, что у проверенного backend «Переговорки».
ARG PYTHON_IMAGE=python:3.12-slim@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b
FROM ${PYTHON_IMAGE}

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 PIP_RETRIES=5 PIP_DEFAULT_TIMEOUT=60
WORKDIR /srv/app
COPY requirements.txt constraints.txt ./
RUN pip install -r requirements.txt -c constraints.txt

# Модели NER (navec + slovnet, ~29 МБ): адрес и SHA-256 закреплены в fetch_models.py; отдельный слой — смена кода его не сбрасывает.
# Офлайн-сборка: положите natasha-1.6.0-py3-none-any.whl в services/anonymizer/wheels/ — колесо возьмётся оттуда (сумма проверяется так же).
COPY app/privacy/fetch_models.py /tmp/fetch_models.py
COPY wheels/ /tmp/wheels/
RUN set -e; W=$(ls /tmp/wheels/natasha-*.whl 2>/dev/null | head -1 || true); \
    python /tmp/fetch_models.py /opt/nlp $W && rm -rf /tmp/fetch_models.py /tmp/wheels
ENV PRIVACY_NLP_DIR=/opt/nlp

COPY app ./app
RUN useradd --system --uid 10001 --home-dir /nonexistent --shell /usr/sbin/nologin app
USER 10001
EXPOSE 8091
HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=5 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8091/readyz', timeout=4).status == 200 else 1)"

ARG APP_VERSION=0.0.0
ENV APP_VERSION=${APP_VERSION}
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8091", "--workers", "1", "--no-access-log"]
