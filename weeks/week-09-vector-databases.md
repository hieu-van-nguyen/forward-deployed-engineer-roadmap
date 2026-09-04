# Week 9 — Vector Databases: HNSW, pgvector, Pinecone, Qdrant — Day-by-Day Plan

> **Milestone:** [09 — Vector Databases: HNSW, pgvector, Pinecone, Qdrant](../milestones/09-vector-databases.md)
> **Month:** M3 · **Weeks:** W9–W10 (this plan covers W9, Days 1–7)
> **Pacing note:** The milestone spans W9–W10. This document covers W9. W10 is covered by [Milestone 10 — RAG Systems: Hybrid Search](../milestones/10-rag-systems-hybrid-search.md).
> **Deliverable:** The milestone's key deliverable is a **benchmark report**, not just a script — a filled results table plus a written analysis of which engine fits which client scenario. Day 7 produces that artifact.

> **⚠️ Scope reality check before Day 1:**
> - **Corpus size is ~100k vectors, not 1M+.** The real bottleneck is CPU embedding generation time (MiniLM on CPU), not index build — 1M is a stretch goal once the pipeline is proven correct at 100k.
> - **Pinecone requires an external account + outbound API access** you may not have on this network. pgvector + Qdrant (both local, Dockerized) are the engines you actually build and benchmark. Pinecone gets a thin adapter behind the same `search_fn` interface, explicitly marked untested — its benchmark row stays `N/A`, never fabricated.
> - **`postgres:15-alpine` (used in Week 5) does not have pgvector.** `CREATE EXTENSION vector` will fail with "extension \"vector\" is not available." Use `pgvector/pgvector:pg16` instead.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Environment setup + embeddings | `pgvector/pgvector:pg16` + Qdrant running in Docker; 100k documents embedded |
| 2 | pgvector — correct bulk loading + HNSW | Fixed insert path (`register_vector`, bulk load), HNSW index built and queried |
| 3 | pgvector — IVFFlat, load-order gotcha | IVFFlat index built *after* data load, compared against HNSW |
| 4 | Qdrant — collection, filtered search, aligned IDs | Qdrant collection with the same 100k vectors, ID space matching pgvector's |
| 5 | Ground truth + a benchmark harness that's actually correct | Exact-kNN ground truth via matrix multiply; fixed recall/latency/QPS harness |
| 6 | Run the benchmark across engines + Pinecone adapter stub | Filled-in latency/recall numbers for pgvector (HNSW+IVFFlat) and Qdrant |
| 7 | Benchmark report + engine-selection analysis | The milestone's actual deliverable: report + written recommendation |

---

## Day 1 — Environment Setup + Embeddings

**Goal:** Get a pgvector-capable Postgres and Qdrant running locally, and generate embeddings for a ~100k-document corpus — establishing scope before writing any index code.

### Docker Compose — the Correct pgvector Image

```yaml
# docker-compose.yml — extends the Week 5/8 stack
services:
  pgvector-db:
    image: pgvector/pgvector:pg16    # NOT postgres:15-alpine — that image has no vector extension
    environment:
      POSTGRES_DB: vectordb
      POSTGRES_USER: app
      POSTGRES_PASSWORD: secret
    ports:
      - "5433:5432"
    volumes:
      - pgvectordata:/var/lib/postgresql/data

  qdrant:
    image: qdrant/qdrant:latest
    ports:
      - "6333:6333"
    volumes:
      - qdrantdata:/qdrant/storage

volumes:
  pgvectordata:
  qdrantdata:
```

```bash
docker compose up -d pgvector-db qdrant
docker compose exec pgvector-db psql -U app -d vectordb -c "CREATE EXTENSION IF NOT EXISTS vector;"
# Should succeed silently — if you swap in postgres:15-alpine here instead, this line
# fails with: ERROR: extension "vector" is not available
```

### Corpus and Embeddings — Scope to ~100k, Not 1M

```python
# embed_corpus.py
from sentence_transformers import SentenceTransformer
from datasets import load_dataset
import numpy as np

model = SentenceTransformer("all-MiniLM-L6-v2")   # 384-dim

# Any public text dataset works — subset to 100k rows for a laptop-feasible run
dataset = load_dataset("wikipedia", "20220301.simple", split="train[:100000]")
texts = [row["text"][:1000] for row in dataset]   # truncate long docs

embeddings = model.encode(
    texts, batch_size=64, show_progress_bar=True, convert_to_numpy=True
)
np.save("corpus_embeddings.npy", embeddings)   # shape (100000, 384)

import json
with open("corpus_texts.json", "w") as f:
    json.dump(texts, f)
```

```bash
time python embed_corpus.py
# Expect this to be the slowest step of the whole week — CPU-bound MiniLM inference,
# not index build. Budget real wall-clock time here before planning later days.
```

### Done when
- [ ] `pgvector/pgvector:pg16` running, `CREATE EXTENSION vector` succeeds
- [ ] Qdrant running and reachable on `localhost:6333`
- [ ] ~100k documents embedded, saved to disk (`corpus_embeddings.npy` + `corpus_texts.json`)
- [ ] Can state, from having timed it, how long embedding generation actually took — this is next week's real bottleneck, not index build

---

## Day 2 — pgvector: Correct Bulk Loading + HNSW

**Goal:** Fix the milestone's broken insert path — `psycopg2` doesn't know how to adapt a Python list into a `vector` literal without help — and build an HNSW index.

### The Bug: `emb.tolist()` Becomes an Array Literal, Not a Vector Literal

```python
# milestone's version
cur.executemany(
    "INSERT INTO documents (content, embedding) VALUES (%s, %s)",
    [(text, emb.tolist()) for text, emb in zip(texts, embeddings)]
)
```

Without registering pgvector's adapter, psycopg2 serializes a Python list as a Postgres **array** literal (`{0.1,0.2,...}`), not the `vector` type's literal syntax (`[0.1,0.2,...]`) — every row fails with `column "embedding" is of type vector but expression is of type numeric[]`.

### The Fix — Register the Adapter, and Bulk-Load Instead of Row-by-Row

```python
# load_pgvector.py
import json
import numpy as np
import psycopg2
from psycopg2.extras import execute_values
from pgvector.psycopg2 import register_vector

conn = psycopg2.connect("postgresql://app:secret@localhost:5433/vectordb")
register_vector(conn)   # teaches psycopg2 how to adapt numpy arrays -> vector literals
cur = conn.cursor()

cur.execute("""
    CREATE TABLE IF NOT EXISTS documents (
        id BIGINT PRIMARY KEY,   -- explicit id, NOT BIGSERIAL — see Day 4's ID-alignment note
        content TEXT,
        source VARCHAR(255) DEFAULT 'wiki',
        embedding vector(384)
    );
""")
conn.commit()

embeddings = np.load("corpus_embeddings.npy")
with open("corpus_texts.json") as f:
    texts = json.load(f)

rows = [(i, text, embeddings[i].tolist()) for i, text in enumerate(texts)]

# executemany() is a separate round-trip per row -> minutes for 100k rows.
# execute_values batches into a handful of multi-row INSERTs instead.
execute_values(
    cur,
    "INSERT INTO documents (id, content, embedding) VALUES %s",
    rows,
    page_size=1000,
)
conn.commit()
print(f"Loaded {len(rows)} rows")
```

```bash
time python load_pgvector.py
# Compare mentally against how long executemany() would have taken —
# execute_values should be an order of magnitude faster for 100k rows
```

### Build the HNSW Index

```sql
CREATE INDEX ON documents USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);
```

```bash
docker compose exec pgvector-db psql -U app -d vectordb -c "
CREATE INDEX ON documents USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);
"
# Time this — HNSW build time is the tradeoff for its fast queries
```

### Sanity-Check a Query

```sql
SET hnsw.ef_search = 64;   -- session-scoped: must be set on every new connection, not just once globally

SELECT id, content, 1 - (embedding <=> $1::vector) AS similarity
FROM documents
ORDER BY embedding <=> $1::vector
LIMIT 10;
```

### Done when
- [ ] `register_vector(conn)` called before any insert — confirmed by a successful load with no type errors
- [ ] Bulk load via `execute_values` (or `COPY`), not row-by-row `executemany`
- [ ] `documents.id` set explicitly to the corpus's 0-based row index, not `BIGSERIAL` (this matters for Day 5's recall calculation)
- [ ] HNSW index built; a manual query returns sensible nearest neighbors by eye

---

## Day 3 — pgvector: IVFFlat and the Load-Order Gotcha

**Goal:** Build an IVFFlat index correctly — which requires data to already be loaded — and understand why building it on an empty table silently produces a bad index with no error.

### The Bug: IVFFlat Trained on Nothing

IVFFlat's `lists` clusters are computed via k-means **at index-creation time**, using whatever rows exist in the table at that moment. Creating the index before loading data (or on a half-loaded table) trains the clustering on zero or too-few vectors — Postgres does not error on this; it just silently produces a low-quality index that will show poor recall days later with no obvious cause.

### The Fix — Always Load Data First, Index Second

```sql
-- Only run this AFTER Day 2's full 100k-row load is committed and confirmed
CREATE INDEX ON documents USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 316);   -- sqrt(100000) ≈ 316, per pgvector's own guidance
```

```bash
# Verify row count BEFORE indexing — this is the check that would have caught the bug
docker compose exec pgvector-db psql -U app -d vectordb -c "SELECT count(*) FROM documents;"
# Expect 100000 — if this shows 0 or a partial count, STOP and fix the load before indexing
```

### Query With `n_probes` Tuning

```sql
SET ivfflat.probes = 10;

SELECT id, content, 1 - (embedding <=> $1::vector) AS similarity
FROM documents
ORDER BY embedding <=> $1::vector
LIMIT 10;
```

### Compare HNSW vs. IVFFlat Query Plans

```sql
EXPLAIN ANALYZE
SELECT id FROM documents ORDER BY embedding <=> '[...]'::vector LIMIT 10;
-- Run against each index (drop one, rebuild the other, or use two tables) and compare
-- actual execution time — this is your first real data point for Day 6's benchmark
```

### Done when
- [ ] Row count verified as the full 100k **before** creating the IVFFlat index
- [ ] IVFFlat index built with `lists ≈ sqrt(n_vectors)`
- [ ] Can explain, unprompted, why an IVFFlat index built on an empty table wouldn't error but would silently underperform
- [ ] `EXPLAIN ANALYZE` output captured for both index types

---

## Day 4 — Qdrant: Collection, Filtered Search, Aligned IDs

**Goal:** Load the same 100k vectors into Qdrant — using the **same ID space** as pgvector, since a mismatch here is what silently zeroes out Day 5's recall metric.

### The Bug: Three Different ID Spaces Across Engines

- Ground truth (Day 5) will be a 0-based numpy row index.
- Qdrant's milestone example uses `PointStruct(id=i)` — 0-based. Fine.
- pgvector's milestone example uses `BIGSERIAL` — which assigns **1-based** auto-incrementing IDs, off by one (or more, if any inserts were retried) from both of the above.

If IDs don't line up, `retrieved_ids & true_ids` in the recall calculation intersects almost nothing, and every engine reports recall ≈ 0 — not because search is broken, but because the IDs being compared don't refer to the same underlying vector.

### The Fix — One Canonical ID, Enforced Everywhere

Day 2 already fixed pgvector's side (`documents.id` set explicitly to the row index). Mirror that here:

```python
# load_qdrant.py
import json
import numpy as np
from qdrant_client import QdrantClient
from qdrant_client.models import Distance, VectorParams, PointStruct, HnswConfigDiff

client = QdrantClient("localhost", port=6333)

client.recreate_collection(
    collection_name="documents",
    vectors_config=VectorParams(size=384, distance=Distance.COSINE),
    hnsw_config=HnswConfigDiff(m=16, ef_construct=100),
)

embeddings = np.load("corpus_embeddings.npy")
with open("corpus_texts.json") as f:
    texts = json.load(f)

points = [
    PointStruct(
        id=i,   # matches pgvector's explicit id AND the ground truth's row index — same i, same vector, everywhere
        vector=embeddings[i].tolist(),
        payload={"text": texts[i][:500], "source": "wiki", "category": "general"},
    )
    for i in range(len(texts))
]

# Batch upsert — one giant list works for 100k, but chunk it to avoid a single oversized request
batch_size = 1000
for start in range(0, len(points), batch_size):
    client.upsert(collection_name="documents", points=points[start:start + batch_size])

print(f"Loaded {len(points)} points into Qdrant")
```

### Filtered Search

```python
results = client.search(
    collection_name="documents",
    query_vector=query_embedding.tolist(),
    query_filter=None,   # add a Filter(...) once payload categories are meaningful
    limit=10,
    with_payload=True,
)
for r in results:
    print(r.id, r.score, r.payload["text"][:80])
```

### Done when
- [ ] Qdrant collection loaded with the exact same 100k vectors, `id=i` matching the numpy row index
- [ ] Spot-checked: querying pgvector and Qdrant with the *same* query vector returns overlapping (not identical, but substantially overlapping) top-10 results
- [ ] Filtered search tested with at least one payload condition

---

## Day 5 — Ground Truth + a Correct Benchmark Harness

**Goal:** Fix the milestone's benchmark script, which references a `ground_truth` that's never defined anywhere, and correct its throughput/timing methodology.

### The Bug: No Ground Truth Generation Exists

`benchmark_search()` takes `ground_truth: List[List[int]]` as a parameter, but nothing in the milestone computes it. Without it, recall@k is meaningless — there's nothing to compare retrieved IDs against.

### The Fix — Exact Cosine k-NN via Matrix Multiply

At 100k × 384, exact brute-force cosine search is a single matrix multiply and runs in seconds — no approximate index needed for computing ground truth itself:

```python
# ground_truth.py
import numpy as np

corpus = np.load("corpus_embeddings.npy")               # (100000, 384)
corpus_norm = corpus / np.linalg.norm(corpus, axis=1, keepdims=True)

# Sample ~200 query vectors from the corpus itself (or encode fresh query texts)
rng = np.random.default_rng(42)
query_idx = rng.choice(len(corpus), size=200, replace=False)
queries = corpus_norm[query_idx]

sims = queries @ corpus_norm.T          # (200, 100000) cosine similarities
k = 10
# argpartition is O(n) vs. a full sort's O(n log n) — we only need the top-k, unordered is fine
top_k_unsorted = np.argpartition(-sims, k, axis=1)[:, :k]

ground_truth = [row.tolist() for row in top_k_unsorted]
np.save("query_indices.npy", query_idx)
import json
with open("ground_truth.json", "w") as f:
    json.dump(ground_truth, f)
```

### The Bug: `throughput_qps` Is Just Inverse Latency, Not Real Throughput

```python
# milestone's version
"throughput_qps": 1000 / np.mean(latencies),
```

`1000 / mean_latency_ms` describes how many requests *one serial client* could issue back-to-back — it is not throughput, which requires concurrent load. Reporting this number as "QPS" overstates what a single-threaded benchmark loop actually measured.

### The Fix — Fixed Harness With Warm-Up and Real Sample Size

```python
# benchmark.py
import time
import numpy as np

def benchmark_search(search_fn, queries, ground_truth, k=10, warmup=20):
    # Warm up: first few queries pay connection/cache costs that shouldn't count
    for query in queries[:warmup]:
        search_fn(query, k=k)

    latencies = []
    recalls = []
    for query, gt in zip(queries, ground_truth):
        start = time.perf_counter()
        results = search_fn(query, k=k)
        latencies.append((time.perf_counter() - start) * 1000)  # ms

        retrieved_ids = set(int(r.id) for r in results)   # normalize to int — avoids str/int id mismatches
        true_ids = set(int(g) for g in gt[:k])
        recalls.append(len(retrieved_ids & true_ids) / len(true_ids) if true_ids else 0.0)

    return {
        "p50_latency_ms": np.percentile(latencies, 50),
        "p95_latency_ms": np.percentile(latencies, 95),
        "p99_latency_ms": np.percentile(latencies, 99),
        "recall_at_k": np.mean(recalls),
        "single_client_qps": 1000 / np.mean(latencies),   # renamed — honest about what this metric is
    }
    # Real concurrent-throughput QPS would require a load-testing tool (e.g. locust)
    # issuing overlapping requests from multiple threads/processes — out of scope this week,
    # but name that gap explicitly in the Day 7 report rather than implying this number is it.
```

### Done when
- [ ] `ground_truth.json` generated via exact matrix-multiply k-NN, not left undefined
- [ ] At least 200 queries used (not 10–20) so p95/p99 aren't noise
- [ ] Warm-up queries excluded from timed results
- [ ] Metric renamed to `single_client_qps` with the concurrent-throughput gap explicitly acknowledged, not silently implied

---

## Day 6 — Run the Benchmark + Pinecone Adapter Stub

**Goal:** Actually run the fixed harness against pgvector (HNSW + IVFFlat) and Qdrant, and write a Pinecone adapter behind the same interface without pretending it was tested.

### Wire Up `search_fn` Per Engine

```python
# search_fns.py
import psycopg2
from pgvector.psycopg2 import register_vector
from qdrant_client import QdrantClient

pg_conn = psycopg2.connect("postgresql://app:secret@localhost:5433/vectordb")
register_vector(pg_conn)
qdrant_client = QdrantClient("localhost", port=6333)

class Result:
    def __init__(self, id): self.id = id

def pgvector_hnsw_search(query_vec, k=10):
    cur = pg_conn.cursor()
    cur.execute("SET hnsw.ef_search = 64;")   # per-connection — must be set every time, not assumed sticky
    cur.execute(
        "SELECT id FROM documents ORDER BY embedding <=> %s::vector LIMIT %s",
        (query_vec.tolist(), k),
    )
    return [Result(row[0]) for row in cur.fetchall()]

def pgvector_ivfflat_search(query_vec, k=10):
    cur = pg_conn.cursor()
    cur.execute("SET ivfflat.probes = 10;")
    cur.execute(
        "SELECT id FROM documents ORDER BY embedding <=> %s::vector LIMIT %s",
        (query_vec.tolist(), k),
    )
    return [Result(row[0]) for row in cur.fetchall()]

def qdrant_search(query_vec, k=10):
    hits = qdrant_client.search(
        collection_name="documents", query_vector=query_vec.tolist(), limit=k
    )
    return [Result(h.id) for h in hits]

def pinecone_search_stub(query_vec, k=10):
    """
    Adapter matches the same search_fn(query, k) -> [Result] interface as the
    engines above, so it plugs into benchmark_search() unmodified. NOT executed
    this week — no Pinecone account/API access confirmed available. Left as a
    stub so a future run only needs a real client + API key, no interface changes.
    """
    raise NotImplementedError("Pinecone not benchmarked this week — no verified API access")
```

### Run and Record

```python
# run_benchmark.py
import json
import numpy as np
from search_fns import pgvector_hnsw_search, pgvector_ivfflat_search, qdrant_search
from benchmark import benchmark_search

corpus = np.load("corpus_embeddings.npy")
corpus_norm = corpus / np.linalg.norm(corpus, axis=1, keepdims=True)
query_idx = np.load("query_indices.npy")
queries = corpus_norm[query_idx]
with open("ground_truth.json") as f:
    ground_truth = json.load(f)

for name, fn in [
    ("pgvector_hnsw", pgvector_hnsw_search),
    ("pgvector_ivfflat", pgvector_ivfflat_search),
    ("qdrant_hnsw", qdrant_search),
]:
    result = benchmark_search(fn, queries, ground_truth, k=10)
    print(name, result)
```

```bash
python run_benchmark.py | tee benchmark_results.txt
```

### Done when
- [ ] Real numbers recorded for pgvector HNSW, pgvector IVFFlat, and Qdrant — not placeholders
- [ ] Recall@10 is meaningfully above 0 for all three (confirms Day 4's ID-alignment fix actually worked)
- [ ] Pinecone row explicitly marked untested/`N/A` in raw notes — not fabricated to fill the table
- [ ] `ef_search`/`probes` confirmed set on every query, not assumed to persist across connections

---

## Day 7 — Benchmark Report + Engine-Selection Analysis

**Goal:** Produce the milestone's actual deliverable — a filled results table plus written analysis — not just a script that could theoretically produce one.

### Filled Benchmark Table

| Engine | Index Type | Params | p50 (ms) | p95 (ms) | Recall@10 | Memory (GB) |
|--------|-----------|--------|----------|----------|-----------|-------------|
| pgvector | HNSW | m=16, ef_search=64 | *(your number)* | *(your number)* | *(your number)* | *(your number)* |
| pgvector | IVFFlat | lists=316, probes=10 | *(your number)* | *(your number)* | *(your number)* | *(your number)* |
| Qdrant | HNSW | m=16, ef_construct=100 | *(your number)* | *(your number)* | *(your number)* | *(your number)* |
| Pinecone | Managed | — | N/A | N/A | N/A | N/A |

> Memory: capture via `docker stats` on the respective container right after the benchmark run, not a theoretical estimate.

### Written Analysis — Structure

1. **On-prem vs. cloud** — pgvector wins when the client already runs Postgres and wants zero new infra; Qdrant wins when filtered search at scale or on-disk indexes matter more than reusing existing Postgres ops knowledge; Pinecone wins when the client wants zero ops burden and accepts a cloud dependency + per-query cost.
2. **Scale** — note where HNSW's memory cost becomes the deciding factor (call out your actual `docker stats` numbers here), and where IVFFlat's lower memory but rebuild-on-update cost matters for write-heavy workloads.
3. **Filter requirements** — pgvector's pre-filter (`WHERE source = ...`) vs. Qdrant's native payload filtering; note which one degrades gracefully at high filter selectivity and which doesn't (worth testing directly if the client's use case is filter-heavy).
4. **What this benchmark did *not* measure** — real concurrent throughput (only single-client latency), Pinecone (no verified access), and index build time at the 1M+ scale the milestone originally specified. Name these gaps explicitly rather than letting the table imply more coverage than it has.

### Done when
- [ ] Table filled with real numbers from Day 6, `Pinecone` row honestly `N/A`
- [ ] Written analysis addresses on-prem vs. cloud, scale, and filter requirements as the milestone's checklist requires
- [ ] Explicit "what wasn't measured" section included — a client-facing report that hides its own scope gaps is a bigger credibility risk than one that states them

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [pgvector Docker image](https://hub.docker.com/r/pgvector/pgvector) |
| 2 | [pgvector-python — `register_vector`](https://github.com/pgvector/pgvector-python) |
| 3 | [pgvector — IVFFlat indexing notes](https://github.com/pgvector/pgvector#ivfflat) |
| 4 | [Qdrant Python client docs](https://qdrant.tech/documentation/) |
| 5 | [ANN Benchmarks methodology](https://ann-benchmarks.com) |
| 6 | [Pinecone Python client docs](https://docs.pinecone.io) |
| 7 | [Pinecone Learning Center — choosing a vector DB](https://www.pinecone.io/learn/) |

---

*→ Next: [Milestone 10 — RAG Systems: Hybrid Search](../milestones/10-rag-systems-hybrid-search.md)*
