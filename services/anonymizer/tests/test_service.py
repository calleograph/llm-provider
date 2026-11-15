"""Сервис обезличивания LLM-провайдера: совместная обработка нескольких текстов, независимая проверка, fail closed."""
from __future__ import annotations

from app import main
from app.privacy.engine import Policy
from app.verify import independent_check


def test_message_instruction_and_file_share_one_session_of_replacements(client):
    texts = ["Подготовь письмо для Петрова Сергея Ивановича, его почта petrov@corp.local.",
             "Инструкция: обращайся к Сергею Ивановичу Петрову по имени-отчеству.",
             "Вложение: договор подписал Петров С. И., телефон +7 912 345-67-89."]
    r = client.post("/v1/anonymize", json={"texts": texts, "profile": "basic"})
    assert r.status_code == 200, r.text
    out = r.json()
    assert len(out["texts"]) == 3 and out["verified"] is True
    joined = " ".join(out["texts"])
    assert "Петров" not in joined and "petrov@" not in joined and "345-67-89" not in joined
    alias = [t for t in ("Сергей П.", "Сергея П.", "Сергею П.", "Сергеем П.") if t in joined]
    assert alias, joined
    assert out["stats"].get("person", 0) >= 3 and out["replaced"] >= 5
    assert "replacements" not in out, "исходные значения — только по запросу лаборатории"


def test_secret_is_masked_under_any_profile_and_settings(client):
    for profile in ("basic", "strict", "custom"):
        r = client.post("/v1/anonymize", json={"texts": ["api_key = sk-proj-AbCdEf0123456789AbCdEf0123456789"], "profile": profile,
                                               "settings": {"custom_actions": {k: "keep" for k in ("person", "email", "phone")}}})
        assert r.status_code == 200 and "AbCdEf0123456789" not in r.json()["texts"][0]


def test_independent_check_blocks_leftovers():
    pol = Policy.from_profile("basic")
    assert independent_check("Позвоните +7 912 345-67-89 или на ivanov@example.org", pol)
    assert set(independent_check("ИНН 7707083893", pol)) == {"id_number"}
    assert independent_check("[телефон], [почта 1], Иван И.", pol) == []
    keep = Policy(actions={k: "keep" for k in ("email", "phone", "ip", "id_number", "bank")})
    assert independent_check("ivanov@example.org", keep) == []
    assert set(independent_check("password=S3cr3t-Value-77-XYZ", keep)) == {"secret"}, "секрет — при любых настройках"


def test_verification_failure_blocks_the_whole_request(client, monkeypatch):
    monkeypatch.setattr(main, "independent_check", lambda text, policy: ["phone"])
    r = client.post("/v1/anonymize", json={"texts": ["Привет"], "profile": "basic"})
    assert r.status_code == 422 and r.json()["detail"]["code"] == "verification_failed"
    assert "Привет" not in r.text


def test_not_ready_and_engine_errors_fail_closed(client, monkeypatch):
    monkeypatch.setitem(main._state, "ready", False)
    r = client.post("/v1/anonymize", json={"texts": ["Иван Петров"]})
    assert r.status_code == 503 and "Петров" not in r.text
    assert client.get("/readyz").status_code == 503
    monkeypatch.setitem(main._state, "ready", True)

    def boom(*a, **k):
        raise RuntimeError("Иван Петров")
    monkeypatch.setattr(main, "_process", boom)
    r = client.post("/v1/anonymize", json={"texts": ["Иван Петров"]})
    assert r.status_code == 500 and "Петров" not in r.text


def test_long_text_and_parallel_requests(client):
    import concurrent.futures as cf
    text = ("Иван Петров звонил в ООО «Ромашка» по номеру +7 912 345-67-89, ИНН 7707083893. " * 400)
    with cf.ThreadPoolExecutor(4) as ex:
        res = list(ex.map(lambda _: client.post("/v1/anonymize", json={"texts": [text], "profile": "strict"}), range(4)))
    assert all(r.status_code == 200 for r in res)
    assert all("Петров" not in r.json()["texts"][0] and "7707083893" not in r.json()["texts"][0] for r in res)


def test_ready_only_after_warm_up_and_fail_closed_on_warm_up_error(monkeypatch):
    """/readyz становится 200 только после пробной обработки: первый запрос пользователя не платит за загрузку моделей."""
    calls = []
    monkeypatch.setattr(main.Anonymizer, "process", lambda self, text: calls.append(text) or (_ for _ in ()).throw(RuntimeError("x")))
    monkeypatch.setitem(main._state, "ready", False)
    main._warm()
    assert calls and main._state["ready"] is False and main._state["error"]
    monkeypatch.undo()
    main._warm()
    assert main._state["ready"] is True and main._state["error"] is None


def test_morph_parse_cache_returns_same_immutable_result():
    from app.privacy import names
    m = names.morph()
    a, b = m.parse("Петрову"), m.parse("Петрову")
    assert a is b and isinstance(a, tuple), "разбор слова кэшируется на процесс и не может быть изменён вызывающим"
    assert [p.normal_form for p in a] == [p.normal_form for p in m.parse.__wrapped__("Петрову")]
