# Week 13 — LLM Evaluation: Ragas / DeepEval — Day-by-Day Plan

> **Milestone:** [13 — LLM Evaluation: Ragas / DeepEval](../milestones/13-llm-evaluation-ragas-deepeval.md)
> **Month:** M4 · **Weeks:** W13–W14 (this plan covers W13, Days 1–7)
> **Pacing note:** The milestone spans W13–W14, sharing its window with [Milestone 14 — Golden Datasets](../milestones/14-golden-datasets.md) (100-sample curated benchmark). This week builds a **20+ case starter golden set** just large enough to exercise the eval pipeline honestly — Week 14 formalizes and expands that into the full 100-sample benchmark. Don't over-invest in dataset size this week; the pipeline's correctness is the point.
> **Deliverable:** An automated eval harness that scores a real RAG pipeline against a golden dataset, fails CI on regression, and — critically — tells you when its own scores are untrustworthy (partial failures, missing embeddings config), not just when they're low.

> **⚠️ Scope reality check before Day 1:**
> - **You need a real RAG pipeline to evaluate.** Don't build a new one — reuse [Week 9](./week-09-vector-databases.md)/[Week 10](./week-10-rag-systems-hybrid-search.md)'s local pgvector + BM25 + reranker pipeline, wrapped behind a `.query(question) -> {"answer": ..., "context_chunks": [...]}` interface.
> - **This week unavoidably needs a real OpenAI (or equivalent) API key** — both Ragas and DeepEval's metrics are LLM-as-judge, and there's no meaningful local-model substitute for judge quality this week (same situation as Weeks 11–12). Use `gpt-4o-mini` as the judge while iterating; reserve `gpt-4o` for the final scored run.
> - **Pin `ragas` and `deepeval` versions explicitly** (`ragas==0.1.x` or `==0.2.x`, not a bare `pip install ragas`). Ragas renamed its dataset columns between versions (`question/answer/contexts/ground_truth` → `user_input/response/retrieved_contexts/reference`) — code written against one version silently breaks or misbehaves against the other.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Environment + starter golden dataset + pin versions | 20+ case `eval/golden_dataset.jsonl`, `ragas`/`deepeval` pinned, RAG pipeline wrapper ready |
| 2 | Ragas pipeline — fix the version/column mismatch | Working `evaluate_rag_system()` against your installed Ragas version, verified column schema |
| 3 | Ragas: honest reporting — surface silent NaNs | Summary that reports per-metric failure counts, not just a skipna-smoothed mean |
| 4 | DeepEval — fix the `HallucinationMetric` context bug | `build_test_cases()` populating both `retrieval_context` and `context` correctly |
| 5 | Pytest integration + missing fixture | `conftest.py` with a real `rag_pipeline` fixture, `test_rag_quality.py` passing |
| 6 | Threshold checker + CI — fix the dead guard | `check_thresholds.py` that can't silently skip a missing metric column, wired into GitHub Actions |
| 7 | Full report + failure analysis + run-to-run comparison | Filled evaluation report, root-caused failures, a real (not aspirational) previous-run comparison |

---

## Day 1 — Environment + Starter Golden Dataset + Pin Versions

**Goal:** Get a RAG pipeline wrapper, a small honest golden dataset, and pinned dependency versions in place before writing any eval code against an API that might not match what you install.

```bash
pip install "ragas==0.2.*" "deepeval>=1.0" langchain-openai pandas pytest
# Confirm what actually installed — don't assume:
python -c "import ragas; print(ragas.__version__)"
```

```python
# eval/golden_dataset.jsonl — 20+ Q&A pairs against YOUR reused Week 9/10 pipeline's corpus
# One JSON object per line: {"question": ..., "ground_truth": ...}
{"question": "What is the return policy?", "ground_truth": "Items can be returned within 30 days of purchase with a receipt."}
{"question": "How long does standard shipping take?", "ground_truth": "Standard shipping takes 5-7 business days."}
```

```python
# rag_pipeline_adapter.py — thin wrapper around Week 9/10's pipeline so eval code has a stable interface
class RAGPipelineAdapter:
    def __init__(self, retriever, generator):
        self.retriever = retriever   # Week 10's hybrid retriever
        self.generator = generator   # your LLM answer-generation step

    def query(self, question: str) -> dict:
        chunks = self.retriever.retrieve(question)
        answer = self.generator.generate(question, chunks)
        return {"answer": answer, "context_chunks": [c.text for c in chunks]}
```

### Done when
- [ ] `ragas`/`deepeval` versions pinned in `requirements.txt`, installed version printed and noted
- [ ] `eval/golden_dataset.jsonl` has 20+ real question/ground_truth pairs answerable from your reused corpus (not placeholder text)
- [ ] `RAGPipelineAdapter.query()` tested manually against one golden question, output inspected

---

## Day 2 — Ragas Pipeline: Fix the Version/Column Mismatch

**Goal:** The milestone's Ragas code uses `question`/`answer`/`contexts`/`ground_truth` column names — that's the pre-0.2 schema. Ragas 0.2+ renamed these to `user_input`/`response`/`retrieved_contexts`/`reference`. Verify which your pinned version expects before trusting the pipeline.

### The Bug: Hardcoded Column Names Assume One Specific Ragas Version

```python
rows.append({
    "question": item["question"],        # renamed to "user_input" in Ragas 0.2+
    "answer": result["answer"],           # renamed to "response"
    "contexts": result["contexts"],       # renamed to "retrieved_contexts"
    "ground_truth": item["ground_truth"], # renamed to "reference"
})
dataset = Dataset.from_list(rows)
```

Running this against a mismatched Ragas version doesn't always raise a clean error — depending on the version, it can silently produce `NaN` scores for metrics that can't find their expected column, which then get averaged away by `.mean()` (Day 3's bug).

### The Fix — Build the Row Dict From Whatever Your Installed Version Actually Expects

```python
# eval/ragas_eval.py
import ragas
from ragas import evaluate
from ragas.metrics import faithfulness, answer_relevancy, context_precision, context_recall, answer_correctness
from ragas.llms import LangchainLLMWrapper
from ragas.embeddings import LangchainEmbeddingsWrapper
from langchain_openai import ChatOpenAI, OpenAIEmbeddings
from datasets import Dataset
import pandas as pd
from pathlib import Path

# Verify column schema against the installed version — don't assume
IS_RAGAS_02_PLUS = ragas.__version__.startswith(("0.2", "0.3"))
COL = {
    "question": "user_input" if IS_RAGAS_02_PLUS else "question",
    "answer": "response" if IS_RAGAS_02_PLUS else "answer",
    "contexts": "retrieved_contexts" if IS_RAGAS_02_PLUS else "contexts",
    "ground_truth": "reference" if IS_RAGAS_02_PLUS else "ground_truth",
}

def run_rag_pipeline(question: str, pipeline) -> dict:
    result = pipeline.query(question)
    return {"answer": result["answer"], "contexts": result["context_chunks"]}

def evaluate_rag_system(pipeline, golden_dataset: list[dict], output_dir: str = "eval_results") -> pd.DataFrame:
    Path(output_dir).mkdir(exist_ok=True)

    rows = []
    for item in golden_dataset:
        result = run_rag_pipeline(item["question"], pipeline)
        rows.append({
            COL["question"]: item["question"],
            COL["answer"]: result["answer"],
            COL["contexts"]: result["contexts"],
            COL["ground_truth"]: item["ground_truth"],
        })
    dataset = Dataset.from_list(rows)

    # Both LLM judge AND embeddings must be wired explicitly — answer_relevancy/answer_correctness
    # otherwise fall back to a default OpenAI embeddings client with no visibility into that choice
    eval_llm = LangchainLLMWrapper(ChatOpenAI(model="gpt-4o-mini", temperature=0))
    eval_embeddings = LangchainEmbeddingsWrapper(OpenAIEmbeddings())

    scores = evaluate(
        dataset,
        metrics=[faithfulness, answer_relevancy, context_precision, context_recall, answer_correctness],
        llm=eval_llm,
        embeddings=eval_embeddings,
        raise_exceptions=False,   # batch-tolerant — but see Day 3 for why this needs a companion check
    )
    df = scores.to_pandas()
    df.to_csv(f"{output_dir}/results.csv", index=False)
    return df
```

### Verification

```python
df = evaluate_rag_system(pipeline, load_golden_dataset("eval/golden_dataset.jsonl"))
print(df.columns.tolist())   # confirm the metric columns you expect are actually present
print(df.head())
```

### Done when
- [ ] Printed `ragas.__version__` and confirmed which column schema applies
- [ ] `evaluate_rag_system()` produces a CSV with all five metric columns present — verified by printing `df.columns`, not assumed
- [ ] `embeddings=` explicitly wired, not left to an implicit default

---

## Day 3 — Ragas: Surface Silent NaNs Instead of Averaging Past Them

**Goal:** `raise_exceptions=False` is the right call for a batch eval (one bad case shouldn't kill the whole run) — but the milestone's summary silently uses pandas' default `skipna=True` behavior in `.mean()`, which can make a metric that failed on half your dataset look like a solid score computed from all of it.

### The Bug: `.mean()` Hides How Many Rows Actually Scored

```python
summary = df[["faithfulness", "answer_relevancy", "context_precision",
              "context_recall", "answer_correctness"]].mean()
for metric, score in summary.items():
    status = "✅" if score >= 0.8 else "⚠️" if score >= 0.6 else "❌"
    print(f"{status} {metric}: {score:.3f}")
# If 8 of 20 faithfulness scores are NaN (judge call failed, malformed response, etc.),
# this still prints a clean-looking number computed from only the 12 that succeeded — no warning at all.
```

### The Fix — Report Non-Null Counts Before Trusting Any Mean

```python
def summarize_with_failure_visibility(df: pd.DataFrame, metrics: list[str]) -> pd.DataFrame:
    total = len(df)
    rows = []
    for metric in metrics:
        if metric not in df.columns:
            rows.append({"metric": metric, "mean": None, "scored": 0, "total": total, "note": "column missing"})
            continue
        non_null = df[metric].notna().sum()
        mean_score = df[metric].mean()  # skipna=True by default — now reported alongside the count, not instead of it
        note = "" if non_null == total else f"⚠️ only {non_null}/{total} cases produced a score"
        rows.append({"metric": metric, "mean": mean_score, "scored": non_null, "total": total, "note": note})
    return pd.DataFrame(rows)

summary = summarize_with_failure_visibility(
    df, ["faithfulness", "answer_relevancy", "context_precision", "context_recall", "answer_correctness"]
)
print(summary.to_string(index=False))
```

### Verification — Force a Partial Failure and Confirm It's Visible

```python
# Manually null out a few rows in a metric column to simulate judge-call failures, then re-summarize
df_test = df.copy()
df_test.loc[:2, "faithfulness"] = None
result = summarize_with_failure_visibility(df_test, ["faithfulness"])
assert "only" in result.iloc[0]["note"]   # the gap must be visible, not silently averaged away
```

### Done when
- [ ] Summary reports `scored/total` for every metric, not just a bare mean
- [ ] Forced a partial-NaN scenario and confirmed the failure note appears
- [ ] Decided (and written down) what "too many NaNs to trust this metric" threshold means for your report — e.g. flag any metric scored on <90% of cases

---

## Day 4 — DeepEval: Fix the `HallucinationMetric` Context Bug

**Goal:** `HallucinationMetric` reads `LLMTestCase.context` — a separate field from `retrieval_context` used by the Contextual* metrics. The milestone's `build_test_cases()` only ever sets `retrieval_context`, so `HallucinationMetric` is evaluating against a field that's never populated.

### The Bug: One Field Set, Two Different Fields Needed

```python
def build_test_cases(pipeline, golden_dataset: list[dict]) -> list[LLMTestCase]:
    test_cases = []
    for item in golden_dataset:
        result = pipeline.query(item["question"])
        test_cases.append(LLMTestCase(
            input=item["question"],
            actual_output=result["answer"],
            retrieval_context=result["context_chunks"],   # used by Contextual*Precision/Recall metrics
            expected_output=item["ground_truth"],
            # context is never set — HallucinationMetric needs THIS field, not retrieval_context
        ))
    return test_cases

metrics = [..., HallucinationMetric(threshold=0.3, model="gpt-4o"), ...]
```

Run this as-is and confirm what actually happens in your installed DeepEval version — some versions raise a clear `MissingTestCaseParamsError`, others silently score against an empty list. Either way, don't ship it unverified.

### The Fix — Populate Both Fields, and Use a Cheaper Judge Model for Iteration

```python
def build_test_cases(pipeline, golden_dataset: list[dict]) -> list[LLMTestCase]:
    test_cases = []
    for item in golden_dataset:
        result = pipeline.query(item["question"])
        test_cases.append(LLMTestCase(
            input=item["question"],
            actual_output=result["answer"],
            retrieval_context=result["context_chunks"],  # for Contextual*Precision/Recall
            context=result["context_chunks"],             # for HallucinationMetric — same chunks, different field name DeepEval expects
            expected_output=item["ground_truth"],
        ))
    return test_cases

JUDGE_MODEL = "gpt-4o-mini"   # iterate cheap — 7 metrics x 20+ cases is 140+ judge calls per run

def run_deepeval(pipeline, golden_dataset: list[dict]):
    test_cases = build_test_cases(pipeline, golden_dataset)
    metrics = [
        FaithfulnessMetric(threshold=0.8, model=JUDGE_MODEL),
        AnswerRelevancyMetric(threshold=0.8, model=JUDGE_MODEL),
        ContextualPrecisionMetric(threshold=0.7, model=JUDGE_MODEL),
        ContextualRecallMetric(threshold=0.7, model=JUDGE_MODEL),
        HallucinationMetric(threshold=0.3, model=JUDGE_MODEL),
        BiasMetric(threshold=0.3, model=JUDGE_MODEL),
        ToxicityMetric(threshold=0.1, model=JUDGE_MODEL),
    ]
    return evaluate(test_cases, metrics)
```

### Verification

```python
cases = build_test_cases(pipeline, load_golden_dataset("eval/golden_dataset.jsonl")[:3])
assert all(c.context is not None for c in cases)   # the bug's fix, proven not assumed
results = run_deepeval(pipeline, load_golden_dataset("eval/golden_dataset.jsonl")[:3])
```

### Done when
- [ ] Reproduced the original bug's actual failure mode in your installed DeepEval version (exception vs. silent empty-context scoring) — documented which one you saw
- [ ] `context` field populated alongside `retrieval_context` in every `LLMTestCase`
- [ ] `HallucinationMetric` runs and produces a real (non-error) score
- [ ] Judge model set to `gpt-4o-mini` for iteration; noted where `gpt-4o` will be swapped in for the final Day 7 run

---

## Day 5 — Pytest Integration + the Missing Fixture

**Goal:** The milestone's `test_rag_quality.py` takes a `rag_pipeline` fixture that's referenced but never defined anywhere. Write the `conftest.py` that actually provides it.

```python
# tests/conftest.py
import pytest
from rag_pipeline_adapter import RAGPipelineAdapter
# ... your Week 9/10 retriever/generator construction here

@pytest.fixture(scope="session")
def rag_pipeline():
    """Session-scoped: build the pipeline once, reuse across all eval test cases — not once per test."""
    retriever = build_hybrid_retriever()   # from Week 10
    generator = build_answer_generator()
    return RAGPipelineAdapter(retriever, generator)
```

```python
# tests/test_rag_quality.py
import pytest
from deepeval import assert_test
from deepeval.metrics import FaithfulnessMetric, AnswerRelevancyMetric
from deepeval.test_case import LLMTestCase
import json

def load_golden_cases(path="eval/golden_dataset.jsonl"):
    with open(path) as f:
        return [json.loads(line) for line in f]

GOLDEN_CASES = load_golden_cases()   # all 20+, loaded from Day 1's real file — not a 1-item inline stub

@pytest.mark.parametrize("case", GOLDEN_CASES)
def test_rag_faithfulness(case, rag_pipeline):
    result = rag_pipeline.query(case["question"])
    test_case = LLMTestCase(
        input=case["question"],
        actual_output=result["answer"],
        retrieval_context=result["context_chunks"],
        context=result["context_chunks"],   # Day 4's fix, applied here too
        expected_output=case["ground_truth"],
    )
    assert_test(test_case, [
        FaithfulnessMetric(threshold=0.8, model="gpt-4o-mini"),
        AnswerRelevancyMetric(threshold=0.8, model="gpt-4o-mini"),
    ])
```

### Done when
- [ ] `conftest.py` exists with a real, working `rag_pipeline` fixture — `pytest --fixtures` lists it
- [ ] `pytest tests/test_rag_quality.py` runs against all 20+ golden cases, not a single inline example
- [ ] Fixture is `session`-scoped so the pipeline isn't rebuilt per test case (cost/latency)

---

## Day 6 — Threshold Checker + CI: Fix the Dead Guard

**Goal:** `check_thresholds.py`'s `if metric in means` guard looks like it protects against a missing column — it doesn't, because the line before it already crashes.

### The Bug: The Guard Executes *After* the Crash It's Supposed to Prevent

```python
def check(results_path: str):
    df = pd.read_csv(results_path)
    means = df[list(THRESHOLDS.keys())].mean()   # KeyError here if ANY threshold metric column is absent from the CSV —
                                                   # raised before the "if metric in means" check below ever runs
    failed = []
    for metric, threshold in THRESHOLDS.items():
        if metric in means:   # dead code — by this point, either every column existed, or we already crashed
            ...
```

If Day 2's version mismatch (or any Ragas/DeepEval hiccup) ever produces a results CSV missing one metric column, this script doesn't fail with a clear "missing metric" message — it crashes with a raw `KeyError` on an unrelated line, in CI, at the worst possible moment to debug it.

### The Fix — Select Columns That Exist, Report the Rest as Failures Explicitly

```python
# eval/check_thresholds.py
import pandas as pd
import sys
import argparse

THRESHOLDS = {
    "faithfulness": 0.80,
    "answer_relevancy": 0.80,
    "context_precision": 0.70,
    "context_recall": 0.70,
    "answer_correctness": 0.75,
}

def check(results_path: str):
    df = pd.read_csv(results_path)
    failed = []
    for metric, threshold in THRESHOLDS.items():
        if metric not in df.columns:
            print(f"❌ {metric}: MISSING from results — check Ragas/DeepEval version and column schema (Day 2)")
            failed.append(f"{metric}=missing")
            continue
        non_null = df[metric].notna().sum()
        score = df[metric].mean()
        note = "" if non_null == len(df) else f" (⚠️ only {non_null}/{len(df)} scored)"
        status = "✅" if score >= threshold else "❌"
        print(f"{status} {metric}: {score:.3f} (threshold: {threshold}){note}")
        if score < threshold or non_null < len(df) * 0.9:   # low coverage fails the gate too, not just a low mean
            failed.append(f"{metric}={score:.3f} < {threshold} or low coverage")
    if failed:
        print(f"\nFAILED: {', '.join(failed)}")
        sys.exit(1)
    print("\nAll metrics passed!")

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--results", required=True)
    args = parser.parse_args()
    check(args.results)
```

### `eval/run_evaluation.py` — the CI-Referenced Script That Was Never Defined

```python
# eval/run_evaluation.py — CI calls this; the milestone's workflow yaml references it but never shows it
import argparse
from ragas_eval import evaluate_rag_system, load_golden_dataset
from rag_pipeline_adapter import RAGPipelineAdapter

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    pipeline = RAGPipelineAdapter(build_hybrid_retriever(), build_answer_generator())
    golden = load_golden_dataset("eval/golden_dataset.jsonl")
    evaluate_rag_system(pipeline, golden, output_dir=args.output)

if __name__ == "__main__":
    main()
```

### Verification — Prove the Fixed Checker Fails Loudly, Not Cryptically

```bash
# Simulate a missing column and confirm a clear message, not a KeyError traceback
python -c "import pandas as pd; pd.DataFrame({'faithfulness':[0.9]}).to_csv('/tmp/partial.csv', index=False)"
python eval/check_thresholds.py --results /tmp/partial.csv
# Expect: "❌ answer_relevancy: MISSING from results..." — not a stack trace
```

### Done when
- [ ] `check_thresholds.py` reports a clear per-metric "MISSING" message instead of crashing on the first absent column
- [ ] Low score-coverage (per Day 3) also fails the gate, not just a low mean
- [ ] `eval/run_evaluation.py` exists and is what CI actually calls
- [ ] GitHub Actions workflow pins `ragas`/`deepeval` versions in the `pip install` step (not bare package names)

---

## Day 7 — Full Report + Failure Analysis + Real Run-to-Run Comparison

**Goal:** Fill the evaluation report template with real numbers from your pipeline, root-cause the worst-scoring cases, and make the "comparison to previous run" section actually possible — the milestone's CI artifacts are SHA-keyed per run with nothing that reads a prior one back.

### The Gap: Artifacts Are Written, Never Read Back

The CI workflow uploads `eval-results-${{ github.sha }}` every run but nothing downloads the *previous* SHA's artifact to diff against — so "Comparison to Previous Run" in the report template has no mechanism behind it.

### The Fix — Persist a Rolling Baseline Alongside the SHA-Keyed Artifact

```python
# eval/compare_to_baseline.py
import pandas as pd
from pathlib import Path

BASELINE_PATH = Path("eval_results/baseline.csv")   # committed to the repo (or a stable artifact path), overwritten only on merge to main

def compare(current_path: str) -> pd.DataFrame:
    current = pd.read_csv(current_path)
    if not BASELINE_PATH.exists():
        print("No baseline yet — this run establishes it.")
        current.to_csv(BASELINE_PATH, index=False)
        return current

    baseline = pd.read_csv(BASELINE_PATH)
    metrics = ["faithfulness", "answer_relevancy", "context_precision", "context_recall", "answer_correctness"]
    rows = []
    for m in metrics:
        if m in current.columns and m in baseline.columns:
            rows.append({"metric": m, "previous": baseline[m].mean(), "current": current[m].mean(),
                         "delta": current[m].mean() - baseline[m].mean()})
    return pd.DataFrame(rows)

print(compare("eval_results/results.csv").to_string(index=False))
```

### Run Full Eval, Fill the Report

```markdown
# RAG Evaluation Report — 2026-09-04

## System Under Test
- Model: gpt-4o (final scored run — gpt-4o-mini used during iteration)
- Embedding: BAAI/bge-small-en-v1.5 (Week 9/10 pipeline)
- Retrieval: Hybrid (dense + BM25 + reranker)

## Results (N=22 golden test cases)
| Metric | Score | Scored/Total | Threshold | Status |
|--------|-------|--------------|-----------|--------|
| Faithfulness | 0.85 | 22/22 | 0.80 | ✅ |
| Answer Relevancy | 0.81 | 22/22 | 0.80 | ✅ |
| Context Precision | 0.71 | 21/22 | 0.70 | ✅ |
| Context Recall | 0.68 | 22/22 | 0.70 | ❌ |
| Answer Correctness | 0.77 | 22/22 | 0.75 | ✅ |

## Failure Analysis
- Context Recall below threshold — 4 cases where ground-truth-relevant chunks weren't retrieved at all
- Root cause: BM25 sparse weight too low for keyword-heavy queries (SKU lookups) — revisit Week 10's fusion weighting
- 1 Context Precision score missing — judge call failed on a case with a very long context window; needs a retry wrapper

## Comparison to Baseline
| Metric | Baseline | Current | Delta |
|--------|---------|---------|-------|
| Faithfulness | — (first run) | 0.85 | n/a |
```

### Done when
- [ ] All checklist items from the milestone verified end-to-end against your fixed code, not the original buggy snippets
- [ ] Report filled with real numbers from an actual run, including scored/total coverage per metric
- [ ] `eval/compare_to_baseline.py` run at least twice (two separate pipeline versions) to prove the comparison mechanism actually works, not just exists
- [ ] One root-caused failure traced back to a specific upstream fix (e.g. Week 10's fusion weighting) — not a vague "needs improvement"

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [Ragas Documentation](https://docs.ragas.io) |
| 2 | [Ragas — migrating to 0.2](https://docs.ragas.io/en/stable/references/) |
| 3 | [pandas — `skipna` behavior](https://pandas.pydata.org/docs/reference/api/pandas.DataFrame.mean.html) |
| 4 | [DeepEval Metrics Reference](https://docs.confident-ai.com/docs/metrics-introduction) |
| 5 | [pytest fixtures](https://docs.pytest.org/en/stable/how-to/fixtures.html) |
| 6 | [GitHub Actions — artifacts](https://docs.github.com/en/actions/using-workflows/storing-workflow-data-as-artifacts) |
| 7 | *Evaluating and Debugging Generative AI* — DeepLearning.AI (free) |

---

*→ Next: [Milestone 14 — Golden Datasets](../milestones/14-golden-datasets.md)*
