"""Замер скорости обезличивания по этапам (docs/ANONYMIZATION.md, «Скорость»). Тексты синтетические, в выводе — только числа.

Холодный старт (импорт, загрузка моделей, первый запрос) и тёплые запросы для типичных сообщений: короткое (1–2 предложения),
среднее (~1500 знаков), длинное (~20 000 знаков), текст DOCX-вложения. Время этапов движка — по обёрткам вокруг его функций
(секреты, структурированные данные, NER, разбор людей, морфология, проверка движка, независимая проверка). Медиана из N прогонов.

    PYTHONPATH=. python -m tests.timing_stages [--runs 7] [--json]
    docker run --rm -v "$PWD/tests:/srv/app/tests:ro" [--cpus 2] <образ anonymizer> python -m tests.timing_stages
"""
from __future__ import annotations

import io
import json
import os
import statistics
import sys
import time
import zipfile
from collections import defaultdict

os.environ.setdefault("PRIVACY_NLP_DIR", os.path.join(os.path.expanduser("~"), ".cache", "llm-provider-nlp"))

T0 = time.monotonic()
from app import main as svc  # noqa: E402
from app.privacy import engine, names  # noqa: E402
from app.privacy.extract import extract  # noqa: E402

IMPORT_MS = (time.monotonic() - T0) * 1000

SHORT = "Привет! Иван Петров просил перезвонить ему по номеру +7 916 123-45-67 до пятницы."
PARA = ("Добрый день, коллеги. По итогам встречи с ООО «Ромашка» Сергей Иванович Кузнецов подготовит договор № 45/2026 до 20 ноября. "
        "Контакты: s.kuznetsov@example.com, тел. 8 (495) 123-45-67, сервер db-prod-01.corp.local (192.0.2.15). "
        "Мария Сидорова согласует сумму 1 250 000 рублей с бухгалтерией, ИНН 7707083893. "
        "Вопросы по доступу — к Алексею Смирнову; пароль от тестового стенда: Qw3rty!2026x. "
        "Следующая встреча в Москве, ул. Тверская, д. 7, офис 12. ")


def medium() -> str:
    out = PARA
    while len(out) < 1500:
        out += PARA.replace("Кузнецов", "Волков").replace("Сидорова", "Орлова")
    return out[:1500]


def long() -> str:
    surn = ["Волков", "Орлов", "Лебедев", "Козлов", "Новиков", "Морозов", "Павлов", "Соколов", "Попов", "Егоров"]
    parts, i = [], 0
    while sum(map(len, parts)) < 20000:
        s = surn[i % len(surn)]
        parts.append(PARA.replace("Кузнецов", s).replace("45/2026", f"{i + 10}/2026").replace("12.", f"{i % 90 + 10}."))
        i += 1
    return "".join(parts)[:20000]


def docx_bytes(text: str) -> bytes:
    """Минимальный DOCX (только document.xml) — как вложение из Word."""
    body = "".join(f"<w:p><w:r><w:t xml:space=\"preserve\">{p}</w:t></w:r></w:p>" for p in text.split(". ") if p)
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml", '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                   '<Default Extension="xml" ContentType="application/xml"/></Types>')
        z.writestr("word/document.xml", '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                   f"<w:body>{body}</w:body></w:document>")
    return buf.getvalue()


# --------------------------------------------------------------------------------------------- обёртки этапов
acc: dict[str, float] = defaultdict(float)


def wrap(obj, attr: str, stage: str) -> None:
    fn = getattr(obj, attr)

    def timed(*a, **kw):
        t = time.perf_counter()
        try:
            return fn(*a, **kw)
        finally:
            acc[stage] += (time.perf_counter() - t) * 1000
    setattr(obj, attr, timed)


def instrument() -> None:
    wrap(engine, "find_secrets", "secrets")
    wrap(engine, "find_structured", "patterns")
    wrap(names, "ner_spans", "ner+morph(ner_spans)")
    wrap(engine.Anonymizer, "_people", "people")
    wrap(engine.Anonymizer, "_verify", "engine_verify")
    wrap(svc, "independent_check", "independent_check")
    m = names.morph()
    wrap(m, "parse", "morph.parse")   # вложено в другие этапы — показывает долю морфологии
    ner = names.models()[0]
    wrap(ner.__class__, "__call__", "slovnet(ner)")


def run(texts: list[str], runs: int) -> dict:
    cfg = svc.Cfg({})
    rows = []
    for _ in range(runs):
        acc.clear()
        t = time.perf_counter()
        svc._process(texts, "strict", cfg, [])
        rows.append({"total": (time.perf_counter() - t) * 1000, **acc})
    keys = sorted({k for r in rows for k in r})
    return {k: round(statistics.median(r.get(k, 0.0) for r in rows), 1) for k in keys}


def main() -> int:
    runs = int(sys.argv[sys.argv.index("--runs") + 1]) if "--runs" in sys.argv else 7
    t = time.monotonic()
    names.models()
    load_ms = (time.monotonic() - t) * 1000
    cfg = svc.Cfg({})
    t = time.perf_counter()
    svc._process([SHORT], "strict", cfg, [])
    first_ms = (time.perf_counter() - t) * 1000
    t = time.perf_counter()
    svc._process([SHORT], "strict", cfg, [])
    second_ms = (time.perf_counter() - t) * 1000
    instrument()
    doc = docx_bytes(medium() * 4)
    t = time.perf_counter()
    doc_text, _ = extract("report.docx", doc)
    extract_ms = (time.perf_counter() - t) * 1000
    cases = {"short": [SHORT], "medium": [medium()], "long": [long()], "message+docx": [SHORT, doc_text]}
    res = {"cold": {"import_ms": round(IMPORT_MS), "models_ms": round(load_ms), "first_short_ms": round(first_ms, 1),
                    "second_short_ms": round(second_ms, 1), "docx_extract_ms": round(extract_ms, 1), "docx_chars": len(doc_text),
                    "cpus": os.cpu_count()},
           "warm": {k: {"chars": sum(map(len, v)), **run(v, runs)} for k, v in cases.items()}}
    if "--json" in sys.argv:
        print(json.dumps(res, ensure_ascii=False))
        return 0
    print("холодный старт:", json.dumps(res["cold"], ensure_ascii=False))
    stages = sorted({k for v in res["warm"].values() for k in v if k not in ("chars", "total")})
    print(f"{'текст':<14}{'знаков':>8}{'итого,мс':>10}" + "".join(f"{s[:18]:>20}" for s in stages))
    for k, v in res["warm"].items():
        print(f"{k:<14}{v['chars']:>8}{v['total']:>10}" + "".join(f"{v.get(s, 0):>20}" for s in stages))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
