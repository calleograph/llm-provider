"""Сверка встроенного обезличивания с эталонными наборами прежнего DocClean (методика «Переговорки», docs/ANONYMIZATION.md).

Наборы синтетические и в репозиторий не входят: путь к каталогу проекта DocClean передаётся аргументом.
Для каждого текста: доля значений «должно исчезнуть», которых нет в результате, и число значений «должно остаться», которые пропали;
плюс время, скорость (знаков/с) и пик памяти процесса. Тексты и найденные значения не печатаются — только счётчики.

    PYTHONPATH=. python -m tests.benchmark_docclean /путь/к/docclean [--profile strict|basic] [--json]
"""
from __future__ import annotations

import json
import os
import resource
import sys
import time
from pathlib import Path

os.environ.setdefault("PRIVACY_NLP_DIR", os.path.join(os.path.expanduser("~"), ".cache", "llm-provider-nlp"))

from app.privacy.engine import PrivacyError  # noqa: E402
from app.privacy.names import models  # noqa: E402
from app.main import Cfg, _process  # noqa: E402


def cases(root: Path) -> list[tuple[str, Path, list[str], list[str]]]:
    fx, tx = root / "tests" / "fixtures", root / "тесты"
    out = []

    def add(name: str, text: Path, man: dict) -> None:
        if text.is_file():
            out.append((name, text, list(man.get("must_remove", [])), list(man.get("must_preserve", []))))

    single = {"dirty_test_1c": tx / "dirty_test_1c.txt", "dirty_test_2": fx / "dirty_test_2_real_world_fragments.txt",
              "dirty_test_3": fx / "dirty_test_3_extended_mixed.txt", "dirty_test_5": tx / "dirty_test_5_counted_mixed.txt",
              "large_mixed_ground_truth": fx / "large_mixed_ground_truth.txt"}
    for name, text in single.items():
        m = fx / f"{name}_manifest.json"
        if m.is_file():
            add(name, text, json.loads(m.read_text(encoding="utf-8")))
    m = fx / "dirty_tests_7_16_manifest.json"
    if m.is_file():
        for fname, man in json.loads(m.read_text(encoding="utf-8"))["cases"].items():
            add(fname.removesuffix(".txt"), tx / fname, man)
    m = fx / "ground_truth.json"
    if m.is_file():
        for item in json.loads(m.read_text(encoding="utf-8")).get("fixtures", []):
            if str(item.get("file", "")).endswith(".txt"):
                add(item["file"].removesuffix(".txt"), fx / item["file"], item)
    return out


def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    profile = "basic" if "--profile" in sys.argv and sys.argv[sys.argv.index("--profile") + 1] == "basic" else "strict"
    if not args:
        print(__doc__)
        return 2
    t0 = time.monotonic()
    models()
    load_s = time.monotonic() - t0
    rows, total_rm, removed, total_keep, lost, chars, secs, blocked = [], 0, 0, 0, 0, 0, 0.0, 0
    policy_name = profile
    for name, path, must_remove, must_keep in cases(Path(args[0])):
        text = path.read_text(encoding="utf-8")
        t = time.monotonic()
        try:
            out = _process([text], policy_name, Cfg({}), [])["texts"][0]     # тот же путь, что у запроса к сервису (с повторной проверкой)
            leak = []
        except PrivacyError:
            out, leak = "", ["blocked"]
        dt = time.monotonic() - t
        rm = sum(1 for v in must_remove if v and v not in out) if out else len(must_remove)
        lk = sum(1 for v in must_keep if v and v not in out) if out else len(must_keep)
        blocked += bool(leak)
        rows.append({"case": name, "chars": len(text), "must_remove": len(must_remove), "removed": rm, "must_keep": len(must_keep), "lost": lk,
                     "ms": int(dt * 1000), "blocked_by_check": bool(leak)})
        total_rm, removed, total_keep, lost, chars, secs = total_rm + len(must_remove), removed + rm, total_keep + len(must_keep), lost + lk, chars + len(text), secs + dt
    peak_mb = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024
    summary = {"profile": profile, "cases": len(rows), "removed_pct": round(100 * removed / max(1, total_rm), 1), "must_remove": total_rm,
               "over_replaced": lost, "must_keep": total_keep, "blocked_by_check": blocked, "chars": chars,
               "chars_per_s": int(chars / max(secs, 1e-6)), "model_load_s": round(load_s, 2), "peak_rss_mb": round(peak_mb)}
    if "--json" in sys.argv:
        print(json.dumps({"summary": summary, "rows": rows}, ensure_ascii=False, indent=1))
    else:
        for r in rows:
            print(f"{r['case'][:40]:40} {r['removed']:4}/{r['must_remove']:<4} keep-lost {r['lost']:2}/{r['must_keep']:<3} {r['ms']:6} ms{'  CHECK-BLOCK' if r['blocked_by_check'] else ''}")
        print(json.dumps(summary, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
