# Milestone 13 — LLM Evaluation: Ragas / DeepEval

| Field | Value |
|---|---|
| **Month** | M4 |
| **Weeks** | W13–W14 |
| **Priority** | P1 — Critical |
| **Domain** | LLM Evaluation |
| **Objective** | Build automated evaluation pipelines (Ragas/DeepEval) measuring Groundedness, Faithfulness, Recall |
| **Key Deliverable** | Automated test harness scoring LLM responses against golden dataset |

---

## Why This Matters for FDEs

"Does your AI actually work?" is the first question clients and their IT leadership will ask. FDEs must answer this quantitatively, not with vibes. An automated eval pipeline lets you run regression tests before client demos, catch prompt regressions, and present improvement metrics over time.

---

## RAG Evaluation Metrics

| Metric | Measures | How |
|--------|---------|-----|
| **Faithfulness** | Does the answer contain only info from the context? | LLM-as-judge: compare answer claims to context |
| **Answer Relevancy** | Does the answer actually address the question? | LLM generates hypothetical questions; cosine sim to original |
| **Context Precision** | Are the retrieved chunks relevant to the question? | LLM judges if each chunk was useful |
| **Context Recall** | Did retrieval find all necessary information? | Compare retrieved context to ground truth |
| **Answer Correctness** | Is the answer factually correct? | Compare to ground truth answer |

---

## Ragas Evaluation

```python
# eval/ragas_eval.py
from ragas import evaluate
from ragas.metrics import (
    faithfulness,
    answer_relevancy,
    context_precision,
    context_recall,
    answer_correctness,
)
from ragas.llms import LangchainLLMWrapper
from langchain_openai import ChatOpenAI
from datasets import Dataset
import pandas as pd
import json
from pathlib import Path

def load_golden_dataset(path: str) -> list[dict]:
    """Load Q&A golden dataset from JSON/JSONL file."""
    with open(path) as f:
        return [json.loads(line) for line in f]

def run_rag_pipeline(question: str, pipeline) -> dict:
    """Run your RAG pipeline and collect question, contexts, answer."""
    result = pipeline.query(question)
    return {
        "question": question,
        "answer": result["answer"],
        "contexts": result["context_chunks"],
    }

def evaluate_rag_system(
    pipeline,
    golden_dataset: list[dict],
    output_dir: str = "eval_results",
) -> pd.DataFrame:
    Path(output_dir).mkdir(exist_ok=True)

    # Collect RAG outputs
    rows = []
    for item in golden_dataset:
        result = run_rag_pipeline(item["question"], pipeline)
        rows.append({
            "question": item["question"],
            "answer": result["answer"],
            "contexts": result["contexts"],
            "ground_truth": item["ground_truth"],
        })

    dataset = Dataset.from_list(rows)

    # Configure evaluator LLM
    eval_llm = LangchainLLMWrapper(ChatOpenAI(model="gpt-4o", temperature=0))

    scores = evaluate(
        dataset,
        metrics=[
            faithfulness,
            answer_relevancy,
            context_precision,
            context_recall,
            answer_correctness,
        ],
        llm=eval_llm,
        raise_exceptions=False,
    )

    df = scores.to_pandas()
    df.to_csv(f"{output_dir}/results.csv", index=False)

    # Print summary
    summary = df[["faithfulness", "answer_relevancy", "context_precision",
                  "context_recall", "answer_correctness"]].mean()
    print("\n=== Evaluation Summary ===")
    for metric, score in summary.items():
        status = "✅" if score >= 0.8 else "⚠️" if score >= 0.6 else "❌"
        print(f"{status} {metric}: {score:.3f}")

    return df
```

---

## DeepEval Evaluation

```python
# eval/deepeval_eval.py
from deepeval import evaluate
from deepeval.metrics import (
    FaithfulnessMetric,
    AnswerRelevancyMetric,
    ContextualPrecisionMetric,
    ContextualRecallMetric,
    HallucinationMetric,
    BiasMetric,
    ToxicityMetric,
)
from deepeval.test_case import LLMTestCase

def build_test_cases(pipeline, golden_dataset: list[dict]) -> list[LLMTestCase]:
    test_cases = []
    for item in golden_dataset:
        result = pipeline.query(item["question"])
        test_cases.append(LLMTestCase(
            input=item["question"],
            actual_output=result["answer"],
            retrieval_context=result["context_chunks"],
            expected_output=item["ground_truth"],
        ))
    return test_cases

def run_deepeval(pipeline, golden_dataset: list[dict]) -> None:
    test_cases = build_test_cases(pipeline, golden_dataset)

    metrics = [
        FaithfulnessMetric(threshold=0.8, model="gpt-4o"),
        AnswerRelevancyMetric(threshold=0.8, model="gpt-4o"),
        ContextualPrecisionMetric(threshold=0.7, model="gpt-4o"),
        ContextualRecallMetric(threshold=0.7, model="gpt-4o"),
        HallucinationMetric(threshold=0.3, model="gpt-4o"),  # Lower = better
        BiasMetric(threshold=0.3, model="gpt-4o"),
        ToxicityMetric(threshold=0.1, model="gpt-4o"),
    ]

    # Evaluate all test cases
    results = evaluate(test_cases, metrics)
    return results
```

### Pytest Integration

```python
# tests/test_rag_quality.py
import pytest
from deepeval import assert_test
from deepeval.metrics import FaithfulnessMetric, AnswerRelevancyMetric
from deepeval.test_case import LLMTestCase

GOLDEN_CASES = [
    {
        "input": "What is the return policy?",
        "expected_output": "You can return items within 30 days of purchase with a receipt.",
        "retrieval_context": [
            "Our return policy allows returns within 30 days of purchase with original receipt.",
        ],
    },
    # Add 20+ test cases from your golden dataset
]

@pytest.mark.parametrize("case", GOLDEN_CASES)
def test_rag_faithfulness(case, rag_pipeline):
    result = rag_pipeline.query(case["input"])
    test_case = LLMTestCase(
        input=case["input"],
        actual_output=result["answer"],
        retrieval_context=result["context_chunks"],
        expected_output=case["expected_output"],
    )
    assert_test(test_case, [
        FaithfulnessMetric(threshold=0.8, model="gpt-4o"),
        AnswerRelevancyMetric(threshold=0.8, model="gpt-4o"),
    ])
```

---

## CI/CD Integration

```yaml
# .github/workflows/eval.yml
name: LLM Evaluation CI
on:
  pull_request:
    paths: ['rag/**', 'prompts/**', 'eval/**']
  schedule:
    - cron: '0 6 * * 1'  # Weekly regression Monday 6am

jobs:
  evaluate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-python@v5
        with:
          python-version: '3.11'
      - run: pip install ragas deepeval openai
      - name: Run eval
        env:
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
        run: python eval/run_evaluation.py --output eval_results/

      - name: Check thresholds
        run: python eval/check_thresholds.py --results eval_results/results.csv

      - name: Upload results
        uses: actions/upload-artifact@v4
        with:
          name: eval-results-${{ github.sha }}
          path: eval_results/
```

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
    means = df[list(THRESHOLDS.keys())].mean()
    failed = []
    for metric, threshold in THRESHOLDS.items():
        if metric in means:
            score = means[metric]
            print(f"{metric}: {score:.3f} (threshold: {threshold}) {'✅' if score >= threshold else '❌'}")
            if score < threshold:
                failed.append(f"{metric}={score:.3f} < {threshold}")
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

---

## Evaluation Report Template

```markdown
# RAG Evaluation Report — {Date}

## System Under Test
- Model: gpt-4o
- Embedding: BAAI/bge-small-en-v1.5
- Retrieval: Hybrid (dense + BM25 + reranker)

## Results (N=50 test cases)

| Metric | Score | Threshold | Status |
|--------|-------|-----------|--------|
| Faithfulness | 0.87 | 0.80 | ✅ |
| Answer Relevancy | 0.83 | 0.80 | ✅ |
| Context Precision | 0.74 | 0.70 | ✅ |
| Context Recall | 0.71 | 0.70 | ✅ |
| Answer Correctness | 0.79 | 0.75 | ✅ |

## Failure Analysis
- 6 cases where faithfulness < 0.6: [list questions]
- Root cause: model added information not in retrieved context
- Fix: tighten system prompt with "ONLY use provided context"

## Comparison to Previous Run (2 weeks ago)
| Metric | Previous | Current | Delta |
|--------|---------|---------|-------|
| Faithfulness | 0.81 | 0.87 | +0.06 |
```

---

## Checklist

- [ ] 20+ golden test cases in `eval/golden_dataset.jsonl`
- [ ] Ragas evaluation pipeline running and producing CSV results
- [ ] DeepEval pytest integration with `assert_test()`
- [ ] Threshold checker script that exits non-zero on failure
- [ ] GitHub Actions workflow running eval on PR to prompt/RAG changes
- [ ] Evaluation report template filled with real numbers
- [ ] Failure analysis for bottom 10% cases

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Evaluating Language Models* | Various (arXiv survey papers) | Overview of LLM evaluation methodology — automated metrics, human evaluation, and benchmark design |
| *AI Engineering* | Chip Huyen | Comprehensive chapter on LLM evaluation — metric selection, eval pipelines, and production monitoring |
| *Building LLM-Powered Applications* | Valentina Alto | Practical RAG evaluation patterns including Ragas integration and interpretation of scores |
| *Designing Machine Learning Systems* | Chip Huyen | Offline vs. online evaluation, slicing metrics, and monitoring deployed models |
| *The Pragmatic Programmer* | David Thomas & Andrew Hunt | Testing philosophy applicable to LLM systems — write tests that can actually fail |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Ragas Documentation | [docs.ragas.io](https://docs.ragas.io) | Complete reference for all Ragas metrics — formulas, interpretation, and configuration |
| DeepEval Documentation | [docs.confident-ai.com](https://docs.confident-ai.com) | DeepEval metrics, pytest integration, and CI/CD setup guide |
| HELM Benchmark | [crfm.stanford.edu/helm](https://crfm.stanford.edu/helm/) | Stanford's Holistic Evaluation of Language Models — framework for comprehensive LLM evaluation |
| LangSmith Evaluation Docs | [docs.smith.langchain.com/evaluation](https://docs.smith.langchain.com/evaluation) | Running evaluations in LangSmith — datasets, evaluators, and experiment tracking |
| RAGAs GitHub | [github.com/explodinggradients/ragas](https://github.com/explodinggradients/ragas) | Source code and examples — useful for understanding metric implementation |
| Anthropic's Eval Research | [anthropic.com/research](https://www.anthropic.com/research) | Research papers on LLM evaluation methodology from Anthropic |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Evaluating and Debugging Generative AI* | DeepLearning.AI (free) | Weights & Biases course on LLM evaluation, experiment tracking, and debugging |
| *Quality and Safety for LLM Applications* | DeepLearning.AI (free) | LLM output quality, safety evaluation, and production testing |
| *MLOps Specialization* | Coursera / DeepLearning.AI | Model evaluation, monitoring, and continuous improvement pipelines |
