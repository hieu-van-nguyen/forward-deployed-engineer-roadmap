# Week 14 — Golden Datasets: Evaluation Benchmark Curation — Day-by-Day Plan

> **Milestone:** [14 — Golden Datasets: Evaluation Benchmark Curation](../milestones/14-golden-datasets.md)
> **Month:** M4 · **Weeks:** W13–W14 (this plan covers W14, Days 1–7)
> **Pacing note:** This shares its W13–W14 window with [Milestone 13 — LLM Evaluation: Ragas/DeepEval](../milestones/13-llm-evaluation-ragas-deepeval.md), whose Week 13 plan built a 20-case *starter* golden set to prove the eval pipeline worked. This week replaces that starter set with a properly curated, taxonomy-diverse, source-attributed dataset — and re-runs Week 13's Ragas/DeepEval pipeline against it at the end.
> **Deliverable:** A version-controlled `eval/golden_dataset.jsonl` with human-verified ground truth, source attribution, and validated question-type diversity — the credibility artifact you show a client, not just a file that exists.

> **⚠️ Scope reality check before Day 1:**
> - **The milestone's target of 100 fully human-annotated, cross-verified samples is a team-scale target**, not a solo one-week target. Scope this week to **~30–40 self-annotated samples** done for real (real documents, real human review, real validation), and document in your `eval/README.md` exactly how the same workflow scales to 100+ with more annotator time. Doing 30 rigorously beats doing 100 by rubber-stamping LLM output.
> - **The milestone's generation prompt can't actually produce every category in its own taxonomy.** `GENERATION_PROMPT`'s type enum only offers `factual|procedural|comparative|adversarial` — there's no `multi-hop` or `temporal`, yet the Quality Targets table demands ≥20 multi-hop questions. Multi-hop questions require combining *multiple* chunks by definition — a single-chunk generation prompt structurally cannot produce them. Day 3 builds a separate generation path for these.
> - **Annotator agreement (≥85% on 20 overlap samples) needs two annotators** — as a solo learner you don't have one. Day 5 gives you a documented workaround (self-annotate a 20-sample overlap set twice, a week apart, blind to your first pass) rather than silently dropping the metric.
> - **Reuse Week 9/10's actual document corpus** for source material — don't invent synthetic policy documents. Real chunking, real text, real page/section references.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Source inventory + real chunking (the milestone skips this step) | Chunked, indexed source documents ready for question generation |
| 2 | Candidate generation — fix the JSON-mode + schema-mismatch bugs | 60+ raw candidate questions across factual/procedural/comparative/adversarial |
| 3 | Multi-hop + temporal generation — the taxonomy gap | 20+ multi-hop and temporal candidates via a cross-chunk generation path |
| 4 | Human annotation CLI — fix the broken resume/dedup logic | 30–40 approved records with real `ground_truth_context`/`expected_citations` |
| 5 | Validation — fix the assert-first ordering + missing diversity check | `validate_dataset.py` that reports diagnostics before failing, enforces the "no type >40%" rule |
| 6 | Schema authoring + version control | `schemas/golden_record_schema.json`, dataset committed to git with a real `eval/README.md` |
| 7 | Re-run Week 13's eval pipeline against the new dataset | Fresh baseline, before/after comparison, final report |

---

## Day 1 — Source Inventory + Real Chunking

**Goal:** The milestone's `inventory_sources.py` only lists whole files (path, size) — it never actually splits them into passages, yet `generate_candidates.py` takes a `chunk` string as if chunking already happened. Close that gap before generating anything.

```python
# scripts/inventory_sources.py — as given in the milestone, unchanged
import os
from pathlib import Path

def inventory_documents(docs_dir: str) -> list[dict]:
    docs = []
    for p in Path(docs_dir).rglob("*"):
        if p.suffix in {'.pdf', '.docx', '.txt', '.md'}:
            docs.append({
                "path": str(p), "name": p.stem, "type": p.suffix,
                "size_kb": round(p.stat().st_size / 1024, 1),
            })
    return docs
```

### The Gap: Nothing Between "List of Files" and "chunk: str"

```python
# scripts/chunk_sources.py — the missing link, reusing Week 9/10's chunking logic
from pathlib import Path
from dataclasses import dataclass

@dataclass
class Chunk:
    chunk_id: str
    source: str           # matches "source" field in the golden record schema
    page: int | None
    section: str | None
    text: str

def chunk_document(path: str, chunk_size: int = 500, overlap: int = 50) -> list[Chunk]:
    """Reuse Week 9/10's splitter — same chunk boundaries the RAG pipeline actually indexes,
    so ground_truth_context in the golden dataset matches what retrieval can actually return."""
    text = Path(path).read_text(encoding="utf-8", errors="ignore")
    words = text.split()
    chunks = []
    i, chunk_num = 0, 0
    while i < len(words):
        piece = " ".join(words[i:i + chunk_size])
        chunks.append(Chunk(
            chunk_id=f"{Path(path).stem}-{chunk_num:03d}",
            source=Path(path).name,
            page=None, section=None,
            text=piece,
        ))
        i += chunk_size - overlap
        chunk_num += 1
    return chunks
```

### Verification

```python
docs = inventory_documents("data/week10_corpus")
all_chunks = [c for d in docs for c in chunk_document(d["path"])]
print(f"{len(docs)} documents -> {len(all_chunks)} chunks")
assert len(all_chunks) >= 20, "Need enough chunks to source 30-40 diverse questions from"
```

### Done when
- [ ] Real document corpus reused from Week 9/10 (not placeholder text)
- [ ] `chunk_document()` produces chunks with stable IDs and source attribution
- [ ] Chunk boundaries match (or are documented as intentionally different from) what the RAG pipeline actually indexes

---

## Day 2 — Candidate Generation: Fix the JSON-Mode + Schema Bugs

**Goal:** Two real bugs in the milestone's generation code: the JSON mode is asked to return an array when `json_object` mode requires an object root, and the record it eventually produces doesn't carry the fields the dataset schema (and Week 13's Ragas eval) actually needs.

### The Bug: `json_object` Mode Requires an Object Root, Not an Array

```python
GENERATION_PROMPT = """... Format as JSON array:
[
  {"question": "...", "question_type": "...", ...}
]
Document excerpt: {text}
"""

def generate_questions(chunk: str, n: int = 5) -> list[dict]:
    response = client.chat.completions.create(
        model="gpt-4o",
        response_format={"type": "json_object"},   # requires a JSON *object*, not array, at the root
        messages=[{"role": "user", "content": GENERATION_PROMPT.format(text=chunk)}],
        temperature=0.7,
    )
    return json.loads(response.choices[0].message.content)
    # depending on model behavior this either errors, or wraps your array in an unpredictable
    # key you didn't ask for — and `n` is passed in but never used anywhere in the prompt or call
```

### The Fix — Wrap as an Object, Use the `n` Parameter, Use `gpt-4o-mini` for Generation

```python
# scripts/generate_candidates.py
from openai import OpenAI
import json

client = OpenAI()

GENERATION_PROMPT = """Given the following document excerpt, generate {n} diverse questions
that could be answered using ONLY this text.

Include a mix of: direct factual questions, "How do I..." procedural questions,
comparative questions (if applicable), and edge cases or tricky phrasings.

Respond with a JSON object of exactly this shape:
{{"questions": [
  {{"question": "...", "question_type": "factual|procedural|comparative|adversarial",
    "difficulty": "easy|medium|hard", "expected_answer_summary": "brief summary of correct answer"}}
]}}

Document excerpt:
{text}
"""

def generate_questions(chunk_text: str, source: str, n: int = 5) -> list[dict]:
    response = client.chat.completions.create(
        model="gpt-4o-mini",   # candidates are pre-human-review — don't spend gpt-4o here
        response_format={"type": "json_object"},
        messages=[{"role": "user", "content": GENERATION_PROMPT.format(text=chunk_text, n=n)}],
        temperature=0.7,
    )
    parsed = json.loads(response.choices[0].message.content)
    candidates = parsed["questions"]
    # Attach fields the milestone's own schema needs but generation never provided
    for c in candidates:
        c["source"] = source
        c["ground_truth_context_text"] = chunk_text[:500]
    return candidates
```

### Verification

```python
chunk = all_chunks[0]
candidates = generate_questions(chunk.text, chunk.source, n=5)
assert isinstance(candidates, list) and len(candidates) > 0
assert all("source" in c for c in candidates)
```

### Done when
- [ ] `response_format={"type": "json_object"}` paired with a prompt whose example output is an object, not a bare array
- [ ] `n` parameter actually controls how many questions are requested
- [ ] Each candidate carries `source` and the raw chunk text — not just a floating question string
- [ ] 60+ raw candidates generated across at least 3 source documents

---

## Day 3 — Multi-Hop + Temporal Generation: The Taxonomy Gap

**Goal:** `GENERATION_PROMPT`'s type enum only supports `factual|procedural|comparative|adversarial` — there's structurally no way for a single-chunk prompt to produce a genuine multi-hop question, yet the Quality Targets table requires ≥20 of them. Build the missing generation path.

### The Gap: Single-Chunk Generation Cannot Produce Cross-Chunk Questions

A multi-hop question ("Who is responsible for approving refunds over $10k?") requires combining facts from *two different chunks* (e.g., one defining approval thresholds, another defining role responsibilities). Day 2's `generate_questions(chunk_text, ...)` only ever sees one chunk — it cannot manufacture a real cross-chunk dependency, only fake the appearance of one.

### The Fix — A Chunk-Pairing Generation Path

```python
# scripts/generate_multihop.py
import itertools

MULTIHOP_PROMPT = """You are given two excerpts from related documents. Generate 2 questions
that can ONLY be answered correctly by combining information from BOTH excerpts —
not answerable from either excerpt alone.

Also generate 1 temporal question if either excerpt references dates, versions, or changes over time.

Respond as JSON: {{"questions": [
  {{"question": "...", "question_type": "multi-hop|temporal",
    "difficulty": "medium|hard", "expected_answer_summary": "..."}}
]}}

Excerpt A ({source_a}):
{text_a}

Excerpt B ({source_b}):
{text_b}
"""

def generate_multihop_candidates(chunks: list[Chunk], max_pairs: int = 15) -> list[dict]:
    """Pair chunks from DIFFERENT source documents — same-document pairs are more likely
    to be trivially answerable from one and don't test real cross-source retrieval."""
    cross_doc_pairs = [
        (a, b) for a, b in itertools.combinations(chunks, 2) if a.source != b.source
    ][:max_pairs]

    all_candidates = []
    for a, b in cross_doc_pairs:
        response = client.chat.completions.create(
            model="gpt-4o-mini",
            response_format={"type": "json_object"},
            messages=[{"role": "user", "content": MULTIHOP_PROMPT.format(
                source_a=a.source, text_a=a.text, source_b=b.source, text_b=b.text)}],
        )
        parsed = json.loads(response.choices[0].message.content)
        for c in parsed["questions"]:
            c["source"] = f"{a.source}+{b.source}"   # both sources attributed, not one
            c["ground_truth_context_text"] = f"{a.text[:300]}\n---\n{b.text[:300]}"
        all_candidates.extend(parsed["questions"])
    return all_candidates
```

### Verification

```python
multihop = generate_multihop_candidates(all_chunks, max_pairs=15)
mh_count = sum(1 for c in multihop if c["question_type"] == "multi-hop")
print(f"Generated {mh_count} multi-hop candidates (target: 20+ before human filtering)")
# Manually read 3 of them — confirm they genuinely require BOTH excerpts, not just one
```

### Done when
- [ ] At least 20 multi-hop candidates generated from genuinely cross-document chunk pairs
- [ ] Manually verified (read, don't trust) that 3+ sampled multi-hop candidates actually require both sources
- [ ] Temporal candidates generated where source documents contain dates/versions

---

## Day 4 — Human Annotation CLI: Fix the Broken Resume/Dedup Logic

**Goal:** The milestone's `annotate.py` has a dedup check that never fires, because candidates never carry the `id` field it checks against — and the record it writes doesn't match the dataset's own documented schema.

### The Bug: Dedup Checks a Field That Doesn't Exist on Candidates

```python
candidates = [json.loads(l) for l in open(candidates_file)]
existing_ids = set()
if Path(output_file).exists():
    existing_ids = {json.loads(l)["id"] for l in open(output_file)}
...
for i, cand in enumerate(candidates):
    if cand.get("id") in existing_ids:   # cand never has an "id" key — generate_questions()
        continue                          # never assigns one — this branch NEVER triggers
```

Consequence: stop the annotation session and restart it later, and every candidate — including ones you already approved last time — gets presented again. Approve one twice, and you get two different `QA-NNN` records for the same question in your "golden" dataset.

Second bug: the record it writes doesn't match the schema the milestone itself documents (`ground_truth_context`, `expected_citations`, `version`, `created_at` are all missing) — which means "Source-attributed ≥90%" is unmeasurable and Week 13's Ragas eval has no `contexts` field to score `context_precision`/`context_recall` against.

### The Fix — Dedup on Question Text, Write the Full Documented Schema

```python
# scripts/annotate.py
import json
from pathlib import Path
from datetime import date
import typer

app = typer.Typer()

@app.command()
def annotate(
    candidates_file: str = "candidates.jsonl",
    output_file: str = "golden_dataset.jsonl",
    annotator: str = typer.Option(..., prompt="Your email"),
):
    candidates = [json.loads(l) for l in open(candidates_file)]
    existing_records = [json.loads(l) for l in open(output_file)] if Path(output_file).exists() else []
    existing_questions = {r["question"].strip().lower() for r in existing_records}  # dedup on CONTENT, not a never-set id
    next_num = len(existing_records) + 1

    approved = 0
    with open(output_file, "a") as out:
        for i, cand in enumerate(candidates):
            if cand["question"].strip().lower() in existing_questions:
                continue   # now this actually fires on re-runs

            print(f"\n[{i+1}/{len(candidates)}] Question: {cand['question']}")
            print(f"Expected answer: {cand.get('expected_answer_summary', 'N/A')}")
            print(f"Source: {cand.get('source', 'N/A')}")
            print("\nOptions: [a]pprove | [e]dit | [s]kip | [q]uit")

            action = input("> ").strip().lower()
            if action == 'q':
                break
            elif action == 's':
                continue
            elif action in ('a', 'e'):
                ground_truth = cand.get("expected_answer_summary", "")
                if action == 'e':
                    print(f"Current GT: {ground_truth}")
                    ground_truth = input("Enter corrected ground truth: ").strip()

                record = {
                    "id": f"QA-{next_num:03d}",
                    "version": "1.0",
                    "created_at": str(date.today()),
                    "annotator": annotator,
                    "question": cand["question"],
                    "question_type": cand.get("question_type", "factual"),
                    "difficulty": cand.get("difficulty", "medium"),
                    "ground_truth": ground_truth,
                    "ground_truth_context": [{
                        "source": cand.get("source", ""),
                        "text": cand.get("ground_truth_context_text", ""),
                    }],
                    "tags": cand.get("tags", []),
                    "expected_citations": [cand.get("source", "")],
                }
                out.write(json.dumps(record) + "\n")
                out.flush()
                existing_questions.add(cand["question"].strip().lower())
                next_num += 1
                approved += 1
                print(f"✅ Approved (total: {approved})")

    print(f"\nAnnotation complete. {approved} records added.")
```

### Verification

```bash
# Run once, approve 3, quit. Run again on the same candidates file — confirm the same
# 3 questions are NOT re-presented, and no duplicate QA-NNN entries exist for them.
python scripts/annotate.py --candidates-file candidates.jsonl --output-file golden_dataset.jsonl
python -c "
import json
from collections import Counter
recs = [json.loads(l) for l in open('golden_dataset.jsonl')]
qs = Counter(r['question'].strip().lower() for r in recs)
dupes = {q: n for q, n in qs.items() if n > 1}
assert not dupes, f'Duplicate questions slipped through: {dupes}'
"
```

### Done when
- [ ] Restarting annotation mid-session doesn't re-present already-approved questions
- [ ] Every written record includes `ground_truth_context`, `expected_citations`, `version`, `created_at` — matching the documented schema, not a stripped-down subset
- [ ] Ran the duplicate-check script above and confirmed zero duplicates
- [ ] 30–40 records approved across factual, procedural, comparative, adversarial, multi-hop, and temporal types

---

## Day 5 — Validation: Fix the Assert-First Ordering + the Missing Diversity Check

**Goal:** The milestone's `validate_dataset.py` asserts `len(records) >= 100` as its *first* line — which means every other diagnostic (type distribution, empty ground truths, annotator counts) never runs at all while you're still below 100, which is every day of this week except possibly the last. And the checklist demands "no single type > 40%" but no code actually checks it.

### The Bug: The Most Useful Diagnostics Are Unreachable During Curation

```python
def validate_golden_dataset(path: str) -> dict:
    records = [json.loads(l) for l in open(path)]
    assert len(records) >= 100, f"Need 100+ samples, got {len(records)}"   # crashes here at 35 records
    ids = [r["id"] for r in records]                                       # never reached
    assert len(set(ids)) == len(ids), "Duplicate IDs found"                # never reached
    type_dist = Counter(r["question_type"] for r in records)               # never reached
    ...
```

### The Fix — Report Everything, Fail Loudly Only at the End, Enforce the Diversity Rule

```python
# scripts/validate_dataset.py
import json
from collections import Counter

def validate_golden_dataset(path: str, min_samples: int = 30) -> dict:
    records = [json.loads(l) for l in open(path)]
    problems = []

    if len(records) < min_samples:
        problems.append(f"Need {min_samples}+ samples, got {len(records)}")

    ids = [r["id"] for r in records]
    if len(set(ids)) != len(ids):
        dup_ids = [i for i, n in Counter(ids).items() if n > 1]
        problems.append(f"Duplicate IDs: {dup_ids}")

    questions = [r["question"].strip().lower() for r in records]
    if len(set(questions)) != len(questions):
        problems.append("Duplicate question text found — annotation dedup bug (Day 4) may have recurred")

    type_dist = Counter(r["question_type"] for r in records)
    print(f"Question type distribution: {dict(type_dist)}")
    total = len(records)
    for qtype, count in type_dist.items():
        pct = count / total if total else 0
        if pct > 0.40:
            problems.append(f"Type '{qtype}' is {pct:.0%} of dataset — exceeds 40% diversity cap")

    difficulty_dist = Counter(r["difficulty"] for r in records)
    print(f"Difficulty distribution: {dict(difficulty_dist)}")

    empty_gt = [r["id"] for r in records if not r.get("ground_truth")]
    if empty_gt:
        problems.append(f"Empty ground truths: {empty_gt}")

    unsourced = [r["id"] for r in records if not r.get("ground_truth_context")]
    if unsourced:
        source_pct = 1 - len(unsourced) / total if total else 0
        if source_pct < 0.90:
            problems.append(f"Only {source_pct:.0%} source-attributed — below 90% target")

    annotators = Counter(r.get("annotator") for r in records)
    print(f"Annotators: {dict(annotators)}")

    result = {
        "total": total, "type_distribution": dict(type_dist),
        "difficulty_distribution": dict(difficulty_dist),
        "annotators": dict(annotators), "problems": problems,
    }
    if problems:
        print(f"\n⚠️  {len(problems)} issue(s) found:")
        for p in problems:
            print(f"  - {p}")
    else:
        print("\n✅ All checks passed.")
    return result

if __name__ == "__main__":
    import sys
    result = validate_golden_dataset("eval/golden_dataset.jsonl", min_samples=30)
    sys.exit(1 if result["problems"] else 0)
```

### Verification

```bash
python scripts/validate_dataset.py
# Confirm: distribution printouts appear even with 35 records, not just a crash message
```

### Done when
- [ ] Validation runs to completion and prints diagnostics at any dataset size, not just ≥100
- [ ] "No single type > 40%" is an actual enforced check, not just a checklist line
- [ ] Duplicate question text (not just duplicate IDs) is checked — this is what would have caught Day 4's original bug
- [ ] Script exits non-zero on real problems, usable as a CI/pre-commit gate

---

## Day 6 — Schema Authoring + Version Control

**Goal:** The milestone's file structure lists `schemas/golden_record_schema.json` but never shows it — write the schema that Day 4's records actually need to satisfy, and get the dataset under version control with real documentation.

```python
# schemas/golden_record_schema.json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "required": ["id", "version", "created_at", "annotator", "question",
               "question_type", "difficulty", "ground_truth", "ground_truth_context"],
  "properties": {
    "id": {"type": "string", "pattern": "^QA-[0-9]{3,}$"},
    "version": {"type": "string"},
    "created_at": {"type": "string", "format": "date"},
    "annotator": {"type": "string", "format": "email"},
    "question": {"type": "string", "minLength": 5},
    "question_type": {"enum": ["factual", "procedural", "comparative",
                                "adversarial", "multi-hop", "temporal"]},
    "difficulty": {"enum": ["easy", "medium", "hard"]},
    "ground_truth": {"type": "string", "minLength": 1},
    "ground_truth_context": {
      "type": "array", "minItems": 1,
      "items": {"type": "object", "required": ["source", "text"]}
    },
    "expected_citations": {"type": "array", "items": {"type": "string"}},
    "tags": {"type": "array", "items": {"type": "string"}}
  }
}
```

```python
# scripts/schema_check.py — validate every record against the schema, not just ad-hoc field checks
import json, jsonschema

schema = json.load(open("eval/schemas/golden_record_schema.json"))
records = [json.loads(l) for l in open("eval/golden_dataset.jsonl")]
errors = []
for r in records:
    try:
        jsonschema.validate(r, schema)
    except jsonschema.ValidationError as e:
        errors.append((r.get("id", "?"), e.message))
if errors:
    for rid, msg in errors:
        print(f"❌ {rid}: {msg}")
else:
    print(f"✅ All {len(records)} records conform to schema")
```

```bash
git add eval/golden_dataset.jsonl eval/schemas/golden_record_schema.json eval/README.md
git commit -m "Curate v1.0 golden dataset (35 records, 6 question types)"
```

### `eval/README.md` — Document Both the Real Scope and the Scale-Up Path

```markdown
# Golden Dataset

Current: 35 human-annotated records (target for a client engagement: 100+).
See "Scope reality check" in weeks/week-14-golden-datasets.md for why this week
targeted 35 rather than 100, and how to extend it.

## Adding new questions
1. Add source docs to `data/` and run `scripts/chunk_sources.py`
2. `python scripts/generate_candidates.py` (single-chunk) and
   `python scripts/generate_multihop.py` (cross-chunk, for multi-hop/temporal)
3. `python scripts/annotate.py` to review and approve
4. `python scripts/validate_dataset.py` — must pass with zero problems
5. `python scripts/schema_check.py` — must pass with zero errors
```

### Done when
- [ ] `schemas/golden_record_schema.json` exists and every record validates against it
- [ ] Dataset committed to git with a real commit message (not a placeholder)
- [ ] `eval/README.md` documents both current scope and how to extend to 100+
- [ ] Annotator agreement workaround executed: re-annotate a 20-record overlap blind, a week apart if timeline allows, or documented as a known gap for a solo learner

---

## Day 7 — Re-Run Week 13's Eval Pipeline Against the New Dataset

**Goal:** The whole point of curating this dataset is to score the RAG pipeline against something better than Week 13's 20-case starter set. Do that, and treat the result as a fresh baseline — not a diff against the old starter set, which measured a different, smaller dataset.

### The Footgun: Don't Diff Against the Old Baseline

Week 13's `eval/compare_to_baseline.py` persists `eval_results/baseline.csv` from the 20-case starter run. Comparing this week's 35-case results against it would conflate "the dataset changed" with "the pipeline got better/worse" — not a valid comparison. Reset the baseline explicitly.

```bash
# Re-establish a baseline on the NEW dataset — do not diff against the Week 13 starter-set baseline
rm eval_results/baseline.csv   # explicit, not silent — old baseline was scored on a different dataset
python eval/run_evaluation.py --output eval_results/$(date +%Y%m%d)
python eval/check_thresholds.py --results eval_results/$(date +%Y%m%d)/results.csv
python eval/compare_to_baseline.py eval_results/$(date +%Y%m%d)/results.csv   # establishes the new baseline
```

### Final Report

```markdown
# RAG Evaluation Report — 2026-09-04 (v2, curated golden dataset)

## Dataset
- 35 records (up from Week 13's 20-case starter set)
- Types: factual 12, procedural 5, comparative 4, adversarial 4, multi-hop 7, temporal 3
- Source-attributed: 34/35 (97%)

## Results vs. Week 13 Starter-Set Scores (informational only — different datasets, not a valid delta)
| Metric | W13 starter (N=22) | W14 curated (N=35) |
|--------|--------------------|--------------------|
| Faithfulness | 0.85 | 0.81 |
| Context Recall | 0.68 | 0.62 |

## Failure Analysis
- Multi-hop questions score lowest on Context Recall (0.51) — confirms Day 3's cross-document
  chunks are genuinely harder for the retriever, not an artifact of bad question generation
- 1 adversarial question incorrectly answered with fabricated policy — logged for Week 15/16
  guardrail follow-up
```

### Done when
- [ ] Week 13's eval pipeline runs end-to-end against the new 30–40 record dataset without code changes (proves the pipeline's schema handling, from Week 13 Day 2, actually holds up against a differently-shaped dataset)
- [ ] Old starter-set baseline explicitly reset, not silently diffed against
- [ ] Report distinguishes "dataset changed" from "pipeline changed" — no invalid delta claims
- [ ] At least one failure pattern traced to a specific root cause (e.g., multi-hop retrieval gap), not a vague "needs improvement"

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [LangChain Text Splitters](https://python.langchain.com/docs/how_to/#text-splitters) |
| 2 | [OpenAI Structured Outputs / JSON mode](https://platform.openai.com/docs/guides/structured-outputs) |
| 3 | *Data-Centric AI* — DeepLearning.AI (free) |
| 4 | [Typer Documentation](https://typer.tiangolo.com) |
| 5 | [JSON Schema Documentation](https://json-schema.org/learn/getting-started-step-by-step) |
| 6 | [Human-in-the-Loop Machine Learning](https://www.manning.com/books/human-in-the-loop-machine-learning) — Robert Monarch |
| 7 | [Ragas Documentation](https://docs.ragas.io) (from Week 13) |

---

*→ Next: [Milestone 15 — Telemetry & Tracing: OpenTelemetry](../milestones/15-telemetry-tracing-opentelemetry.md)*
