# Milestone 14 — Golden Datasets: Evaluation Benchmark Curation

| Field | Value |
|---|---|
| **Month** | M4 |
| **Weeks** | W13–W14 |
| **Priority** | P1 — Critical |
| **Domain** | Golden Datasets |
| **Objective** | Curate a 100-sample domain-specific golden evaluation dataset with ground truth annotations |
| **Key Deliverable** | Structured evaluation benchmark dataset (JSON/CSV) |

---

## Why This Matters for FDEs

Without a golden dataset, you can't prove your AI works, measure improvements, or catch regressions. Clients want to see numbers. A properly curated golden dataset is the foundation of every eval pipeline and the credibility artifact you show to stakeholders.

---

## What Makes a Good Golden Dataset

| Property | Why it matters |
|----------|---------------|
| **Domain-specific** | Generic Q&A doesn't reflect client reality |
| **Diverse** | Cover all question types the system will face |
| **Adversarial** | Include edge cases and tricky questions |
| **Ground truth annotated** | Correct answer must be human-verified |
| **Source-attributed** | Each question linked to a specific document/chunk |
| **Version controlled** | Track changes to the dataset over time |

---

## Dataset Schema

```json
{
  "id": "QA-001",
  "version": "1.0",
  "created_at": "2024-01-15",
  "annotator": "john.smith@company.com",
  "question": "What is the maximum refund amount for enterprise customers?",
  "question_type": "factual",
  "difficulty": "medium",
  "ground_truth": "Enterprise customers can receive a full refund up to $50,000 per transaction within 90 days of purchase, subject to approval from the enterprise account team.",
  "ground_truth_context": [
    {
      "source": "enterprise-policy-v3.pdf",
      "page": 12,
      "section": "4.2 Enterprise Refund Policy",
      "text": "Enterprise customers are eligible for full refunds up to $50,000 per transaction..."
    }
  ],
  "tags": ["refunds", "enterprise", "policy"],
  "expected_citations": ["enterprise-policy-v3.pdf"],
  "notes": "Tested against edge case: customer asking about corporate vs enterprise tier"
}
```

---

## Question Type Taxonomy

Aim for diversity across these categories:

| Type | % of dataset | Description | Example |
|------|-------------|-------------|---------|
| Factual lookup | 30% | Specific fact retrievable from one source | "What is the SLA for P1 incidents?" |
| Multi-hop | 20% | Answer requires combining multiple chunks | "Who is responsible for approving refunds over $10k?" |
| Comparative | 15% | Compare two entities or policies | "How does the enterprise plan differ from the pro plan?" |
| Procedural | 15% | Step-by-step instructions | "How do I submit a support ticket?" |
| Temporal | 10% | Time-sensitive or date-dependent | "What changed in the Q4 2024 policy update?" |
| Adversarial | 10% | Questions designed to cause hallucination | "What is our policy on returns from the moon?" (out-of-scope) |

---

## Curation Workflow

### Step 1: Source Document Inventory
```python
# scripts/inventory_sources.py
import os
from pathlib import Path

def inventory_documents(docs_dir: str) -> list[dict]:
    """List all documents that will be indexed."""
    docs = []
    for p in Path(docs_dir).rglob("*"):
        if p.suffix in {'.pdf', '.docx', '.txt', '.md'}:
            docs.append({
                "path": str(p),
                "name": p.stem,
                "type": p.suffix,
                "size_kb": round(p.stat().st_size / 1024, 1),
            })
    return docs
```

### Step 2: Generate Candidate Questions (LLM-assisted)
```python
# scripts/generate_candidates.py
from openai import OpenAI

client = OpenAI()

GENERATION_PROMPT = """Given the following document excerpt, generate 5 diverse questions that could be answered using this text.

Include a mix of:
- Direct factual questions
- "How do I..." procedural questions
- Comparative questions (if applicable)
- Edge cases or tricky phrasings

Format as JSON array:
[
  {
    "question": "...",
    "question_type": "factual|procedural|comparative|adversarial",
    "difficulty": "easy|medium|hard",
    "expected_answer_summary": "brief summary of correct answer"
  }
]

Document excerpt:
{text}
"""

def generate_questions(chunk: str, n: int = 5) -> list[dict]:
    response = client.chat.completions.create(
        model="gpt-4o",
        response_format={"type": "json_object"},
        messages=[
            {"role": "user", "content": GENERATION_PROMPT.format(text=chunk)}
        ],
        temperature=0.7,
    )
    import json
    return json.loads(response.choices[0].message.content)
```

### Step 3: Human Annotation Tool (CLI)
```python
# scripts/annotate.py
"""Interactive CLI for human annotation of golden dataset."""
import json
from pathlib import Path
import typer

app = typer.Typer()

@app.command()
def annotate(
    candidates_file: str = "candidates.jsonl",
    output_file: str = "golden_dataset.jsonl",
    annotator: str = typer.Option(..., prompt="Your email"),
):
    """Annotate candidate Q&A pairs for the golden dataset."""
    candidates = [json.loads(l) for l in open(candidates_file)]
    existing_ids = set()
    if Path(output_file).exists():
        existing_ids = {json.loads(l)["id"] for l in open(output_file)}

    approved = 0
    with open(output_file, "a") as out:
        for i, cand in enumerate(candidates):
            if cand.get("id") in existing_ids:
                continue

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
                    "id": f"QA-{len(existing_ids) + approved + 1:03d}",
                    "question": cand["question"],
                    "question_type": cand.get("question_type", "factual"),
                    "difficulty": cand.get("difficulty", "medium"),
                    "ground_truth": ground_truth,
                    "annotator": annotator,
                    "source": cand.get("source", ""),
                    "tags": cand.get("tags", []),
                }
                out.write(json.dumps(record) + "\n")
                out.flush()
                approved += 1
                print(f"✅ Approved (total: {approved})")

    print(f"\nAnnotation complete. {approved} records added.")
```

### Step 4: Validate Dataset Quality
```python
# scripts/validate_dataset.py
import json
from collections import Counter

def validate_golden_dataset(path: str) -> dict:
    records = [json.loads(l) for l in open(path)]

    # Checks
    assert len(records) >= 100, f"Need 100+ samples, got {len(records)}"

    ids = [r["id"] for r in records]
    assert len(set(ids)) == len(ids), "Duplicate IDs found"

    type_dist = Counter(r["question_type"] for r in records)
    print(f"Question type distribution: {dict(type_dist)}")

    difficulty_dist = Counter(r["difficulty"] for r in records)
    print(f"Difficulty distribution: {dict(difficulty_dist)}")

    # Check for empty ground truths
    empty_gt = [r["id"] for r in records if not r.get("ground_truth")]
    if empty_gt:
        print(f"WARNING: Empty ground truths: {empty_gt}")

    # Check annotator diversity
    annotators = Counter(r.get("annotator") for r in records)
    print(f"Annotators: {dict(annotators)}")

    return {
        "total": len(records),
        "type_distribution": dict(type_dist),
        "difficulty_distribution": dict(difficulty_dist),
        "annotators": dict(annotators),
    }
```

---

## Dataset File Structure

```
eval/
├── golden_dataset.jsonl          # 100 approved Q&A records
├── candidates/
│   ├── policy_docs_candidates.jsonl
│   ├── product_docs_candidates.jsonl
│   └── faq_candidates.jsonl
├── schemas/
│   └── golden_record_schema.json
├── scripts/
│   ├── generate_candidates.py
│   ├── annotate.py
│   └── validate_dataset.py
└── README.md                     # How to extend the dataset
```

---

## Dataset Quality Targets

| Metric | Target |
|--------|--------|
| Total samples | ≥ 100 |
| Factual questions | ≥ 30 |
| Multi-hop questions | ≥ 20 |
| Adversarial/out-of-scope | ≥ 10 |
| Human-annotated | 100% |
| Source-attributed | ≥ 90% |
| Annotator agreement (on 20 overlap) | ≥ 85% |

---

## Checklist

- [ ] Source document inventory (all docs that will be indexed)
- [ ] LLM-generated candidate questions (200+ before filtering)
- [ ] Human annotation of 100 questions (CLI tool used)
- [ ] Question type diversity validated (no single type > 40%)
- [ ] Ground truth answers human-verified against source documents
- [ ] `validate_dataset.py` passes all checks
- [ ] Dataset committed to git (version controlled)
- [ ] `README.md` explaining how to add new questions

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Designing Data-Intensive Applications* | Martin Kleppmann | Data quality, schema design, and consistency — foundational for building reliable annotation pipelines |
| *Human-in-the-Loop Machine Learning* | Robert Monarch | Complete guide to data annotation, active learning, and building high-quality training datasets |
| *Building Machine Learning Powered Applications* | Emmanuel Ameisen | Covers dataset collection, annotation strategy, and iterative dataset improvement with real examples |
| *The Mom Test* | Rob Fitzpatrick | Applies to golden dataset design: how to gather accurate ground truth without leading the annotator |
| *Data-Centric AI* | Andrew Ng (course, free) | Systematically improving datasets rather than just models — directly applicable to golden dataset curation |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Label Studio Documentation | [labelstud.io/guide](https://labelstud.io/guide/) | Open-source annotation tool — configure for Q&A pair labeling with custom label interfaces |
| Argilla Documentation | [docs.argilla.io](https://docs.argilla.io) | Modern data annotation platform for NLP — built specifically for LLM dataset curation and feedback collection |
| Prodigy (Explosion AI) | [prodi.gy](https://prodi.gy) | Annotation tool from spaCy creators — excellent for custom annotation workflows with active learning |
| Hugging Face Datasets | [huggingface.co/docs/datasets](https://huggingface.co/docs/datasets/) | Dataset loading, streaming, and format reference — use for managing and sharing your golden dataset |
| TREC QA Track | [trec.nist.gov/data/qa](https://trec.nist.gov/data/qa.html) | Benchmark QA datasets — study structure and annotation guidelines for professional golden datasets |
| DataComp Leaderboard | [datacomp.ai](https://www.datacomp.ai) | Research on data curation strategies — what makes a high-quality training/eval dataset |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Data-Centric AI* | DeepLearning.AI (free) | Andrew Ng's course on systematic dataset improvement over model tuning |
| *Machine Learning Data Lifecycle in Production* | Coursera / DeepLearning.AI | Data pipelines, schema validation, and dataset versioning for ML |
| *Pair Annotation and Evaluation for LLMs* | Various (arXiv workshops) | Research on annotation methodology for instruction-following datasets |
