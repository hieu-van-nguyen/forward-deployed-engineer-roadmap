# Week 10 — RAG Systems: Hybrid Search (Dense + Sparse + Reranking) — Day-by-Day Plan

> **Milestone:** [10 — RAG Systems: Hybrid Search](../milestones/10-rag-systems-hybrid-search.md)
> **Month:** M3 · **Weeks:** W9–W10 (this plan covers W10, Days 1–7)
> **Pacing note:** The milestone spans W9–W10, same range as Milestone 09. W9 (vector DB fundamentals) was covered by [Week 9's plan](./week-09-vector-databases.md); this document assumes that Postgres/pgvector + Qdrant stack is already running and builds hybrid RAG on top of it.
> **Deliverable:** A working hybrid search engine (dense + BM25 + RRF + cross-encoder rerank) with a documented recall@k comparison: hybrid vs. dense-only vs. sparse-only. That comparison table is the actual checklist deliverable — not just working code.

> **⚠️ Scope reality check before Day 1:**
> - **Embedding model dimension collision with Week 9.** This milestone's `DenseRetriever` defaults to `BAAI/bge-small-en-v1.5` — a **384-dim** model, same dimension as Week 9's `all-MiniLM-L6-v2`. Reusing Week 9's already-indexed vectors here is a trap: Postgres/Qdrant will accept BGE query vectors against a MiniLM-indexed corpus with **no dimension error**, and silently return garbage nearest-neighbors, because both models happen to produce 384-length vectors. Re-embed the corpus with BGE into a **new** table/collection this week — never mix embedding models within one index.
> - **Ragas' metrics are all LLM-judged** (faithfulness, answer_relevancy, context_precision, context_recall) — Ragas needs an OpenAI API key just like generation does. If no key is available, the recall@k retrieval comparison (dense vs. sparse vs. hybrid, computed with plain Python — no LLM required) is the key-free path and the actual checklist deliverable. Ragas + `gpt-4o` end-to-end eval is a stretch goal this week, with Ollama noted as a local fallback.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Ingestion pipeline + BGE re-embedding | Chunked corpus, embedded with BGE into a fresh table/collection |
| 2 | Dense retriever — fix the missing-argument bug | `DenseRetriever` wired to a real index, returning real `Chunk` objects |
| 3 | Sparse retriever (BM25) | In-memory BM25 index over the same corpus, tokenization sanity-checked |
| 4 | Reciprocal Rank Fusion — fix the `k` collision | Fusion combining dense + sparse rankings, correctly parameterized |
| 5 | Cross-encoder reranking + end-to-end pipeline | Full `HybridRAGPipeline.retrieve()` returning top-5 reranked chunks |
| 6 | Retrieval evaluation — the key-free deliverable | recall@k table: hybrid vs. dense-only vs. sparse-only |
| 7 | Generation + FastAPI + (stretch) Ragas | Working `/query` endpoint, written comparison report |

---

## Day 1 — Ingestion Pipeline + BGE Re-Embedding

**Goal:** Chunk a document corpus and embed it with BGE — into storage that is explicitly separate from Week 9's MiniLM-embedded vectors, to avoid the dimension-collision trap.

### Chunking

```python
# ingest/pipeline.py — as given in the milestone, works as-is
import hashlib
from dataclasses import dataclass
from typing import List, Optional
from langchain.text_splitter import RecursiveCharacterTextSplitter

@dataclass
class Chunk:
    text: str
    doc_id: str
    chunk_index: int
    metadata: dict
    embedding: Optional[List[float]] = None

def chunk_document(text, doc_id, metadata, chunk_size=512, chunk_overlap=50) -> List[Chunk]:
    splitter = RecursiveCharacterTextSplitter(
        chunk_size=chunk_size, chunk_overlap=chunk_overlap,
        separators=["\n\n", "\n", ". ", " "],
    )
    texts = splitter.split_text(text)
    return [
        Chunk(text=t, doc_id=doc_id, chunk_index=i,
              metadata={**metadata, "chunk_index": i, "total_chunks": len(texts)})
        for i, t in enumerate(texts)
    ]
```

### The Bug: Reusing Week 9's Index Would Silently Corrupt Results

Both `all-MiniLM-L6-v2` (Week 9) and `BAAI/bge-small-en-v1.5` (this milestone) output 384-dim vectors. If BGE query embeddings are searched against a `vector(384)` column populated with MiniLM embeddings, Postgres/Qdrant execute the query without error — the dimensions match — but the resulting distances are meaningless, because the two models don't share a common embedding space. There is no exception to catch here; it just quietly returns bad neighbors.

### The Fix — New Table, New Collection, Explicit Naming

```sql
-- New table, deliberately not reusing Week 9's `documents`
CREATE TABLE rag_chunks (
    id BIGINT PRIMARY KEY,
    doc_id TEXT,
    chunk_index INT,
    text TEXT,
    metadata JSONB,
    embedding vector(384)
);
```

```python
# Attach embeddings to Chunk objects, then persist — this connects chunking to storage,
# a step the milestone's code never shows explicitly
from sentence_transformers import SentenceTransformer

model = SentenceTransformer("BAAI/bge-small-en-v1.5")
chunks: List[Chunk] = []  # from chunk_document() over your corpus

texts = [c.text for c in chunks]
embeddings = model.encode(texts, normalize_embeddings=True, batch_size=64, show_progress_bar=True)

for c, emb in zip(chunks, embeddings):
    c.embedding = emb.tolist()

# Global integer id per chunk, matching Week 9's id-alignment fix — needed by Day 2's adapter
chunk_id_lookup = {i: c for i, c in enumerate(chunks)}
```

```bash
# Reuse Day 1/2's pgvector loading pattern from Week 9 (register_vector + execute_values)
# to bulk-load rag_chunks — do not skip register_vector(conn) here either
python load_rag_chunks.py
```

### Done when
- [ ] Corpus chunked with `chunk_document()`, overlap confirmed non-zero
- [ ] Embeddings generated with BGE (not reused from Week 9), `normalize_embeddings=True`
- [ ] Loaded into a **new** table/collection (`rag_chunks`), never mixed with Week 9's MiniLM data
- [ ] `chunk_id_lookup: dict[int, Chunk]` built and saved — Day 2 depends on this

---

## Day 2 — Dense Retriever: Fix the Missing-Argument Bug

**Goal:** Fix `DenseRetriever.search()`'s signature mismatch with how the pipeline actually calls it, and wire an adapter that converts raw index hits back into `{"chunk": Chunk, "score": ...}` dicts.

### The Bug: `index` Has No Default, But the Pipeline Never Passes One

```python
# milestone's DenseRetriever
def search(self, query: str, index, k: int = 20) -> List[dict]:
    ...

# milestone's HybridRAGPipeline.retrieve()
dense_results = self.dense.search(query, k=20)   # <-- `index` never supplied
```

This raises `TypeError: search() missing 1 required positional argument: 'index'` the first time `retrieve()` is called — a call-site/signature mismatch that only surfaces at runtime, not at import time.

### The Fix — Bind the Index at Construction Time

```python
# retrieval/dense.py — fixed
from sentence_transformers import SentenceTransformer
import numpy as np
from typing import List

class DenseRetriever:
    def __init__(self, index, chunk_lookup: dict, model_name: str = "BAAI/bge-small-en-v1.5"):
        self.model = SentenceTransformer(model_name)
        self.index = index                # bound once, not passed per-call
        self.chunk_lookup = chunk_lookup  # int id -> Chunk, from Day 1

    def encode(self, texts: List[str]) -> np.ndarray:
        return self.model.encode(texts, normalize_embeddings=True, batch_size=64)

    def search(self, query: str, k: int = 20) -> List[dict]:
        query_emb = self.encode([query])[0]
        raw_hits = self.index.search(query_emb, k=k)  # pgvector cursor rows or Qdrant ScoredPoints

        # Adapter: raw hits are engine-specific shapes, not {"chunk": ..., "score": ...} dicts.
        # RRF and the reranker both expect the latter — this is the missing translation layer.
        return [
            {"chunk": self.chunk_lookup[int(hit_id)], "score": float(score)}
            for hit_id, score in raw_hits
        ]
```

```python
# Thin wrapper so index.search() returns (id, score) pairs uniformly,
# regardless of whether the backend is pgvector or Qdrant
def qdrant_index_search(query_emb, k=20):
    hits = qdrant_client.search(collection_name="rag_chunks", query_vector=query_emb.tolist(), limit=k)
    return [(h.id, h.score) for h in hits]

def pgvector_index_search(query_emb, k=20):
    cur.execute("SET hnsw.ef_search = 64;")
    cur.execute(
        "SELECT id, 1 - (embedding <=> %s::vector) AS score FROM rag_chunks "
        "ORDER BY embedding <=> %s::vector LIMIT %s",
        (query_emb.tolist(), query_emb.tolist(), k),
    )
    return cur.fetchall()
```

### Done when
- [ ] `DenseRetriever.__init__` takes `index` and `chunk_lookup`; `search(query, k)` takes no `index` argument
- [ ] Calling `dense.search("some query")` returns a list of `{"chunk": Chunk, "score": float}` — verified by printing `.chunk.text` on a result, not just that it runs without error
- [ ] Confirmed against both pgvector and Qdrant backends (whichever you carry forward from Week 9)

---

## Day 3 — Sparse Retriever: BM25

**Goal:** Build the BM25 retriever exactly as the milestone specifies — this piece is correct as written — and sanity-check tokenization against a few queries with exact-match terms (order numbers, codes) that dense search tends to miss.

```python
# retrieval/sparse.py — milestone's version, unmodified
from rank_bm25 import BM25Okapi
import re
from typing import List

class BM25Retriever:
    def __init__(self, chunks: List[Chunk]):
        tokenized = [self._tokenize(c.text) for c in chunks]
        self.bm25 = BM25Okapi(tokenized)
        self.chunks = chunks

    def _tokenize(self, text: str) -> List[str]:
        return re.findall(r'\b\w+\b', text.lower())

    def search(self, query: str, k: int = 20) -> List[dict]:
        tokens = self._tokenize(query)
        scores = self.bm25.get_scores(tokens)
        top_k_idx = scores.argsort()[-k:][::-1]
        return [
            {"chunk": self.chunks[i], "score": float(scores[i])}
            for i in top_k_idx
            if scores[i] > 0
        ]
```

### Verification — the Point of Hybrid Search

```python
bm25 = BM25Retriever(chunks)

# Pick 2-3 queries containing exact tokens (an order number, a proper noun, a code)
# that appear verbatim in the corpus, and compare BM25 vs. dense retriever results on them
for query in ["order #A19273", "invoice code XR-4471"]:
    bm25_hits = bm25.search(query, k=5)
    dense_hits = dense_retriever.search(query, k=5)
    print(query, "BM25 top:", [h["chunk"].text[:60] for h in bm25_hits])
    print(query, "Dense top:", [h["chunk"].text[:60] for h in dense_hits])
    # Expect: BM25 finds the exact-match chunk near the top; dense search often doesn't rank it as highly
```

### Done when
- [ ] `BM25Retriever` built over the exact same `rag_chunks` corpus as the dense index (same chunks, same ids)
- [ ] Ran at least 2 exact-match queries and observed BM25 outperforming dense search on them — this is the concrete justification for hybrid search, not just a theoretical claim
- [ ] Noted (for the Day 7 report) that this in-memory `BM25Okapi` index rebuilds on every process start — production alternatives are Postgres `tsvector`/`ts_rank` or Qdrant's native sparse vectors, out of scope to implement this week but worth naming

---

## Day 4 — Reciprocal Rank Fusion: Fix the `k` Parameter Collision

**Goal:** Implement RRF, but first fix a naming collision that would silently break fusion the moment someone tries to change how many results come back.

### The Bug: `k` Means Two Different Things

```python
def reciprocal_rank_fusion(result_lists: List[List[dict]], k: int = 60) -> List[dict]:
    """k=60 is the standard constant (Cormack et al. 2009)."""
```

Everywhere else in this pipeline — `DenseRetriever.search(query, k=20)`, `BM25Retriever.search(query, k=20)`, `reranker.rerank(query, candidates, top_k=5)` — `k` means "how many results to return." Here, `k` means the RRF smoothing constant (standard value 60, a completely different kind of number). Call `reciprocal_rank_fusion(lists, k=10)` intending "give me top 10" and it instead silently changes the fusion math, over-weighting rank-1 results — no error, just numerically wrong fusion.

### The Fix — Rename the Constant

```python
# retrieval/fusion.py — fixed
from collections import defaultdict
from typing import List

def reciprocal_rank_fusion(result_lists: List[List[dict]], rrf_k: int = 60) -> List[dict]:
    """
    Combine multiple ranked lists using RRF.
    rrf_k=60 is the standard smoothing constant (Cormack et al. 2009) — NOT a result-count limit.
    """
    scores = defaultdict(float)
    chunk_map = {}

    for results in result_lists:
        for rank, item in enumerate(results, start=1):
            chunk_id = f"{item['chunk'].doc_id}_{item['chunk'].chunk_index}"
            scores[chunk_id] += 1 / (rrf_k + rank)
            chunk_map[chunk_id] = item["chunk"]

    sorted_ids = sorted(scores.keys(), key=lambda x: scores[x], reverse=True)
    return [{"chunk": chunk_map[cid], "rrf_score": scores[cid]} for cid in sorted_ids]
```

```python
fused = reciprocal_rank_fusion([dense_results, sparse_results], rrf_k=60)
top_40 = fused[:40]   # the *actual* result-count limit lives here, at the call site, not inside the function
```

### Done when
- [ ] Function parameter renamed `rrf_k`, docstring clarifies it is not a result-count limit
- [ ] Confirmed `fused` contains chunks from both dense and sparse lists (spot-check a chunk that only BM25 surfaced still appears)
- [ ] Result-count limiting done explicitly at the call site (`fused[:40]`), not conflated with `rrf_k`

---

## Day 5 — Cross-Encoder Reranking + End-to-End Retrieval Pipeline

**Goal:** Add the reranker and assemble `retrieve()` end-to-end, now that Days 2–4's fixes make every stage's input/output shapes consistent.

```python
# retrieval/reranker.py — milestone's version, works as-is
from sentence_transformers import CrossEncoder
from typing import List

class Reranker:
    def __init__(self, model_name: str = "cross-encoder/ms-marco-MiniLM-L-6-v2"):
        self.model = CrossEncoder(model_name, max_length=512)

    def rerank(self, query: str, candidates: List[dict], top_k: int = 5) -> List[dict]:
        pairs = [(query, item["chunk"].text) for item in candidates]
        scores = self.model.predict(pairs)
        scored = sorted(zip(candidates, scores), key=lambda x: x[1], reverse=True)
        return [{**item, "rerank_score": float(score)} for item, score in scored[:top_k]]
```

```python
# rag/pipeline.py — retrieve() assembled with Days 2-4's fixes wired in
class HybridRAGPipeline:
    def __init__(self, dense_retriever, sparse_retriever, reranker):
        self.dense = dense_retriever      # already bound to its index (Day 2 fix)
        self.sparse = sparse_retriever
        self.reranker = reranker

    def retrieve(self, query: str, top_k: int = 5) -> List[dict]:
        dense_results = self.dense.search(query, k=20)     # no `index` arg — fixed
        sparse_results = self.sparse.search(query, k=20)
        fused = reciprocal_rank_fusion([dense_results, sparse_results], rrf_k=60)  # renamed — fixed
        return self.reranker.rerank(query, fused[:40], top_k=top_k)
```

```python
pipeline = HybridRAGPipeline(dense_retriever, bm25_retriever, Reranker())
results = pipeline.retrieve("order #A19273 refund status")
for r in results:
    print(r["rerank_score"], r["chunk"].text[:80])
```

### Done when
- [ ] `retrieve()` runs end-to-end with no `TypeError`s, no `KeyError`s on `"chunk"`
- [ ] Manually inspected top-5 reranked results for 2-3 queries — do they look more relevant than dense-only or BM25-only top-5?
- [ ] Note: reranking 40 candidates on CPU is the slowest step in `retrieve()` — time it, this matters for Day 6/7's latency discussion

---

## Day 6 — Retrieval Evaluation: The Key-Free Deliverable

**Goal:** Build the recall@k comparison table (hybrid vs. dense-only vs. sparse-only) using plain Python — no LLM, no API key required. This is the milestone's actual checklist line item ("Benchmark: hybrid vs. dense-only vs. sparse-only recall scores documented"), and it doesn't depend on Ragas or OpenAI access.

### Ground Truth for Retrieval (Not Generation)

```python
# Reuse Week 9's ground-truth strategy: for ~20-30 hand-picked queries, manually
# identify which chunk_ids are actually relevant (a few minutes of manual labeling
# over a corpus you already know) — this is the eval set, not an LLM-graded one
eval_queries = [
    {"query": "order #A19273 refund status", "relevant_chunk_ids": ["doc12_3", "doc12_4"]},
    {"query": "how does the VPN reset work", "relevant_chunk_ids": ["doc7_0"]},
    # ... 20-30 total, built from corpus content you actually recognize
]
```

### Recall@k Comparison

```python
# eval/retrieval_eval.py
def chunk_key(chunk) -> str:
    return f"{chunk.doc_id}_{chunk.chunk_index}"

def recall_at_k(retrieved_chunks, relevant_ids, k=5) -> float:
    retrieved_ids = set(chunk_key(item["chunk"]) for item in retrieved_chunks[:k])
    relevant = set(relevant_ids)
    return len(retrieved_ids & relevant) / len(relevant) if relevant else 0.0

results = {"dense_only": [], "sparse_only": [], "hybrid": []}
for case in eval_queries:
    q = case["query"]
    dense_hits = dense_retriever.search(q, k=20)
    sparse_hits = bm25_retriever.search(q, k=20)
    hybrid_hits = pipeline.retrieve(q, top_k=20)   # skip the top_k=5 truncation for a fair recall@5/10/20 comparison

    results["dense_only"].append(recall_at_k(dense_hits, case["relevant_chunk_ids"], k=5))
    results["sparse_only"].append(recall_at_k(sparse_hits, case["relevant_chunk_ids"], k=5))
    results["hybrid"].append(recall_at_k(hybrid_hits, case["relevant_chunk_ids"], k=5))

import numpy as np
for method, scores in results.items():
    print(method, "recall@5:", np.mean(scores))
```

### Done when
- [ ] 20-30 hand-labeled eval queries with known relevant chunk ids
- [ ] recall@5 computed for dense-only, sparse-only, and hybrid — three real numbers, not placeholders
- [ ] At least one query where hybrid clearly beats both individual methods identified and noted (this is what you'll lead with in Day 7's report)

---

## Day 7 — Generation, FastAPI Endpoint, and the Comparison Report

**Goal:** Wire up generation and the API endpoint (fixing the bare-global wiring issue), then write the actual deliverable: the comparison report.

### Fix: `pipeline` as a Bare Global

```python
# milestone's api/routes.py references a global `pipeline` that's never instantiated in that file
from fastapi import FastAPI
from contextlib import asynccontextmanager
from pydantic import BaseModel
import time

@asynccontextmanager
async def lifespan(app: FastAPI):
    # Build once at startup, not as an import-time side effect
    app.state.pipeline = build_hybrid_rag_pipeline()   # your Day 5 construction, wrapped in a function
    yield

app = FastAPI(lifespan=lifespan)

class QueryRequest(BaseModel):
    question: str
    top_k: int = 5

class QueryResponse(BaseModel):
    answer: str
    sources: list
    retrieval_time_ms: float
    generation_time_ms: float

@app.post("/query", response_model=QueryResponse)
async def query_endpoint(req: QueryRequest):
    pipeline = app.state.pipeline
    t0 = time.perf_counter()
    chunks = pipeline.retrieve(req.question, top_k=req.top_k)
    retrieval_time = (time.perf_counter() - t0) * 1000

    t1 = time.perf_counter()
    answer = pipeline.generate(req.question, chunks)   # requires an LLM client — see below
    gen_time = (time.perf_counter() - t1) * 1000

    return QueryResponse(
        answer=answer,
        sources=[c["chunk"].metadata for c in chunks],
        retrieval_time_ms=retrieval_time,
        generation_time_ms=gen_time,
    )
```

### Generation — API-Key-Optional

```python
# If an OpenAI key is available, use it as the milestone specifies.
# If not, Ollama running a local model (e.g. llama3) is a drop-in fallback behind the same interface.
def generate(query: str, context_chunks: list, llm_client) -> str:
    context = "\n\n---\n\n".join(
        f"[Source: {c['chunk'].metadata.get('source', 'unknown')}]\n{c['chunk'].text}"
        for c in context_chunks
    )
    system_prompt = (
        "You are a helpful assistant. Answer using ONLY the provided context. "
        "If insufficient, say so. Always cite the source document."
    )
    response = llm_client.chat.completions.create(
        model="gpt-4o",   # or an Ollama-compatible model name if using a local OpenAI-compatible endpoint
        messages=[
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": f"Context:\n{context}\n\nQuestion: {query}"},
        ],
        temperature=0.1,
    )
    return response.choices[0].message.content
```

### (Stretch) Ragas — Only If a Key Is Available

```python
# Only run this if an OpenAI key (or a configured local judge model) is actually available.
# Ragas' metrics (faithfulness, answer_relevancy, context_precision/recall) are all LLM-judged —
# treat this exactly like Week 9 treated Pinecone: optional, explicitly marked, never faked.
from ragas import evaluate
from ragas.metrics import faithfulness, answer_relevancy, context_recall, context_precision

# scores = evaluate(dataset, metrics=[...])  # run only with confirmed API/judge access
```

### The Comparison Report — the Actual Deliverable

| Method | Recall@5 | Notes |
|--------|----------|-------|
| Dense-only (BGE + HNSW) | *(Day 6 number)* | Misses exact-match entity lookups |
| Sparse-only (BM25) | *(Day 6 number)* | Misses paraphrased/semantic queries |
| Hybrid (RRF + rerank) | *(Day 6 number)* | Should beat both — cite the specific query from Day 6 that proves it |
| Ragas end-to-end (faithfulness/relevancy) | N/A or *(if key available)* | Explicitly mark N/A if no LLM judge access — do not fabricate |

Written analysis should cover: which failure mode each retrieval method has (dense misses codes/IDs, sparse misses paraphrase), why RRF fixes both without needing score normalization, what the cross-encoder rerank buys over raw fusion order, and where latency is actually spent (Day 5's note: reranking 40 candidates dominates retrieval time — this is the number a client will ask about first).

### Done when
- [ ] `/query` endpoint runs end-to-end via `app.state.pipeline`, not a bare unwired global
- [ ] Generation works with either a real OpenAI key or a documented local fallback
- [ ] Comparison report written with real Day 6 numbers and a named example query, Ragas row honestly marked `N/A` if not run
- [ ] Can explain, unprompted, where end-to-end latency is actually spent (retrieval vs. rerank vs. generation)

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [BAAI/bge-small-en-v1.5 model card](https://huggingface.co/BAAI/bge-small-en-v1.5) |
| 2 | [pgvector-python / Qdrant client docs](https://github.com/pgvector/pgvector-python) |
| 3 | [rank_bm25 / BM25 Explained](https://en.wikipedia.org/wiki/Okapi_BM25) |
| 4 | [Reciprocal Rank Fusion (Cormack et al. 2009)](https://plg.uwaterloo.ca/~gvcormac/cormacksigir09-rrf.pdf) |
| 5 | [sentence-transformers CrossEncoder docs](https://www.sbert.net/docs/cross_encoder/usage/usage.html) |
| 6 | [ANN Benchmarks — recall@k methodology](https://ann-benchmarks.com) |
| 7 | [Ragas Documentation](https://docs.ragas.io) |

---

*→ Next: [Milestone 11 — Agentic Workflows: LangGraph](../milestones/11-agentic-workflows-langgraph.md)*
