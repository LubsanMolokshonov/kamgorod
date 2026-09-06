#!/usr/bin/env python3
"""
Заполняет тексты в плане сидовых отзывов (см. scripts/seed-reviews.php).

Зачем отдельный скрипт: с прод-сервера OpenRouter и Yandex Cloud отвечают
403 «Access denied by security policy» (блокировка по IP), поэтому тексты
генерируются на машине, у которой доступ к ИИ есть, а прод только выгружает
план и потом загружает его обратно.

    # на проде
    docker exec pedagogy_web php /var/www/html/scripts/seed-reviews.php \
        --days=365 --per-day=5.5 --dump-plan=/tmp/plan.json
    scp root@prod:/tmp/plan.json .

    # здесь
    OPENROUTER_API_KEY=sk-... python3 scripts/fill-review-texts.py plan.json

    # обратно на прод
    scp plan.filled.json root@prod:/tmp/
    docker exec pedagogy_web php /var/www/html/scripts/seed-reviews.php \
        --load-plan=/tmp/plan.filled.json

Скрипт идемпотентен: строки, где текст уже заполнен, пропускаются, результат
дописывается в plan.filled.json после каждого батча — прогон можно прервать и
запустить заново.
"""

import json
import os
import random
import sys
import time
import urllib.error
import urllib.request

ENDPOINT = "https://openrouter.ai/api/v1/chat/completions"
BATCH = 10
# Модели чередуются — единый стиль на пару тысяч отзывов выглядит синтетически.
MODELS = [
    "google/gemini-2.5-flash",
    "openai/gpt-4o-mini",
    "qwen/qwen-2.5-72b-instruct",
]

SYSTEM = (
    "Ты пишешь короткие реалистичные отзывы от лица российских педагогов "
    "об образовательном портале. Пиши живо, естественно и по-разному, без канцелярита "
    "и шаблонных штампов, как пишут учителя и воспитатели в реальных отзывах."
)


def build_prompt(label, items):
    lines = []
    for n, r in enumerate(items):
        lines.append(f"{n}. [оценка {r['rating']}] [объём: {r['length_hint']}] {r['title'][:160]}")
    return (
        f"Тип продукта: {label}.\n"
        "Ниже список позиций (индекс, оценка автора, требуемый объём и название). Для КАЖДОЙ позиции напиши "
        "один отзыв от лица педагога, который реально участвовал/прошёл/опубликовал.\n"
        "Требования:\n"
        "— соблюдай указанный для позиции объём;\n"
        "— разнообразь длину и формулировки, не повторяй структуру;\n"
        "— упоминай разные аспекты: организация, скорость получения диплома/сертификата, польза для аттестации "
        "и портфолио, удобство сайта и оплаты, оперативность поддержки;\n"
        "— оценка 5 — тёплый положительный тон; 4 — в целом доволен, но с лёгкой ноткой «можно лучше»; "
        "3 — сдержанно-нейтральный с одним конкретным замечанием;\n"
        "— не пиши число оценки в тексте, не используй кавычки «ёлочки», не указывай личные данные;\n"
        "— по-русски.\n"
        'Верни строго JSON: {"reviews":[{"i":0,"text":"..."}, ...]}.\n\n'
        "Позиции:\n" + "\n".join(lines)
    )


def call_model(api_key, model, user_prompt):
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": SYSTEM},
            {"role": "user", "content": user_prompt},
        ],
        "temperature": 0.9,
        "max_tokens": 2600,
        "response_format": {"type": "json_object"},
    }
    req = urllib.request.Request(
        ENDPOINT,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "HTTP-Referer": "https://fgos.pro",
            "X-Title": "fgos.pro",
        },
    )
    with urllib.request.urlopen(req, timeout=150) as resp:
        body = json.loads(resp.read().decode("utf-8"))
    content = body["choices"][0]["message"]["content"]
    # модель иногда оборачивает JSON в ```json ... ```
    content = content.strip()
    if content.startswith("```"):
        content = content.split("```")[1]
        if content.startswith("json"):
            content = content[4:]
    return json.loads(content)


def main():
    if len(sys.argv) < 2:
        sys.exit("usage: fill-review-texts.py plan.json [plan.filled.json]")
    src = sys.argv[1]
    dst = sys.argv[2] if len(sys.argv) > 2 else src.replace(".json", "") + ".filled.json"

    api_key = os.environ.get("OPENROUTER_API_KEY", "")
    if not api_key:
        sys.exit("OPENROUTER_API_KEY не задан в окружении")

    # если уже есть частично заполненный результат — продолжаем с него
    plan = json.load(open(dst if os.path.exists(dst) else src, encoding="utf-8"))
    rows = plan["rows"]

    todo = [i for i, r in enumerate(rows) if r.get("want_text") and not r.get("review_text")]
    print(f"Всего строк: {len(rows)}, нужно текстов: {len(todo)}")

    by_type = {}
    for i in todo:
        by_type.setdefault(rows[i]["entity_type"], []).append(i)

    done = 0
    failed = 0
    model_idx = 0
    for etype, idxs in by_type.items():
        label = rows[idxs[0]]["label"]
        for start in range(0, len(idxs), BATCH):
            chunk = idxs[start:start + BATCH]
            model = MODELS[model_idx % len(MODELS)]
            model_idx += 1
            items = [rows[i] for i in chunk]

            for attempt in range(3):
                try:
                    data = call_model(api_key, model, build_prompt(label, items))
                    by_i = {int(rv["i"]): str(rv.get("text", "")).strip()
                            for rv in data.get("reviews", []) if "i" in rv}
                    got = 0
                    for n, row_index in enumerate(chunk):
                        txt = by_i.get(n, "")
                        if txt:
                            rows[row_index]["review_text"] = txt[:2000]
                            got += 1
                        else:
                            # текста не пришло — строка станет «только звёзды»
                            rows[row_index]["want_text"] = False
                    done += got
                    print(f"  {etype}: {done}/{len(todo)} ({model}, +{got})")
                    break
                except (urllib.error.HTTPError, urllib.error.URLError, ValueError, KeyError) as e:
                    detail = ""
                    if isinstance(e, urllib.error.HTTPError):
                        detail = e.read()[:200].decode("utf-8", "replace")
                    if attempt == 2:
                        for row_index in chunk:
                            rows[row_index]["want_text"] = False
                        failed += len(chunk)
                        print(f"  ! батч {etype} пропущен ({model}): {e} {detail}", file=sys.stderr)
                    else:
                        time.sleep(2 + attempt * 3 + random.random())

            json.dump(plan, open(dst, "w", encoding="utf-8"), ensure_ascii=False, indent=1)

    json.dump(plan, open(dst, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    with_text = sum(1 for r in rows if r.get("review_text"))
    print(f"\nГотово. Текстов: {with_text}, без текста (только звёзды): {len(rows) - with_text}, "
          f"провалено батчей на {failed} строк.\nРезультат: {dst}")


if __name__ == "__main__":
    main()
