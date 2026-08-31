# Milestone 10 — RAG Systems: Hybrid Search (Dense + Sparse + Reranking)

| Field | Value |
|---|---|
| **Month** | M3 |
| **Weeks** | W9–W10 |
| **Priority** | P1 — Critical |
| **Domain** | RAG Systems |
| **Objective** | Build end-to-end Hybrid RAG (Dense vector search + Sparse BM25 keyword search + Reranking model) |
| **Key Deliverable** | Hybrid search engine with re-ranking delivering top accuracy |

---

## Why This Matters for FDEs

Every AI demo involving "chat with your documents" is a RAG system. Naive RAG (embed everything, cosine search) fails on specific entity lookups like order numbers, names, or codes. Hybrid RAG combining dense + sparse retrieval with a reranker is the production-grade pattern that FDEs must be able to build and explain to clients.

---

## RAG Architecture Overview

```
User Query
    │
    ├──▶ Dense Retrieval (HNSW embedding search)  ──▶ Top 20 chunks
    │                                                      │
    └──▶ Sparse Retrieval (BM25 keyword search)  ──▶ Top 20 chunks
                                                          │
                                              Reciprocal Rank Fusion
                                                          │
                                                    Top 40 merged
                                                          │
                                              Cross-Encoder Reranker
                                                          │
                                                    Top 5 context
                                                          │
                                                  LLM Generation
                                                          │
                                                    Final Answer
```

---

## Document Ingestion Pipeline

```python
# ingest/pipeline.py
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

def chunk_document(
    text: str,
    doc_id: str,
    metadata: dict,
    chunk_size: int = 512,
    chunk_overlap: int = 50,
) -> List[Chunk]:
    splitter = RecursiveCharacterTextSplitter(
        chunk_size=chunk_size,
        chunk_overlap=chunk_overlap,
        separators=["\n\n", "\n", ". ", " "],
    )
    texts = splitter.split_text(text)
    return [
        Chunk(
            text=t,
            doc_id=doc_id,
            chunk_index=i,
            metadata={**metadata, "chunk_index": i, "total_chunks": len(texts)},
        )
        for i, t in enumerate(texts)
    ]
```

---

## Dense Retrieval

```python
# retrieval/dense.py
from sentence_transformers import SentenceTransformer
import numpy as np

class DenseRetriever:
    def __init__(self, model_name: str = "BAAI/bge-small-en-v1.5"):
        self.model = SentenceTransformer(model_name)

    def encode(self, texts: List[str]) -> np.ndarray:
        return self.model.encode(
            texts,
            normalize_embeddings=True,  # For cosine similarity via dot product
            batch_size=64,
            show_progress_bar=True,
        )

    def search(self, query: str, index, k: int = 20) -> List[dict]:
        query_emb = self.encode([query])[0]
        results = index.search(query_emb, k=k)  # pgvector / Qdrant / FAISS
        return results
```

---

## Sparse Retrieval: BM25

```python
# retrieval/sparse.py
from rank_bm25 import BM25Okapi
import re

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

---

## Reciprocal Rank Fusion (RRF)

Combines rankings from multiple retrievers without needing score normalization.

```python
# retrieval/fusion.py
from collections import defaultdict

def reciprocal_rank_fusion(
    result_lists: List[List[dict]],
    k: int = 60,
) -> List[dict]:
    """
    Combine multiple ranked lists using RRF.
    k=60 is the standard constant (Cormack et al. 2009).
    """
    scores = defaultdict(float)
    chunk_map = {}

    for results in result_lists:
        for rank, item in enumerate(results, start=1):
            chunk_id = item["chunk"].doc_id + f"_{item['chunk'].chunk_index}"
            scores[chunk_id] += 1 / (k + rank)
            chunk_map[chunk_id] = item["chunk"]

    sorted_ids = sorted(scores.keys(), key=lambda x: scores[x], reverse=True)
    return [
        {"chunk": chunk_map[cid], "rrf_score": scores[cid]}
        for cid in sorted_ids
    ]
```

---

## Cross-Encoder Reranker

```python
# retrieval/reranker.py
from sentence_transformers import CrossEncoder

class Reranker:
    def __init__(self, model_name: str = "cross-encoder/ms-marco-MiniLM-L-6-v2"):
        self.model = CrossEncoder(model_name, max_length=512)

    def rerank(self, query: str, candidates: List[dict], top_k: int = 5) -> List[dict]:
        pairs = [(query, item["chunk"].text) for item in candidates]
        scores = self.model.predict(pairs)
        scored = sorted(
            zip(candidates, scores),
            key=lambda x: x[1],
            reverse=True,
        )
        return [
            {**item, "rerank_score": float(score)}
            for item, score in scored[:top_k]
        ]
```

---

## End-to-End Hybrid RAG Pipeline

```python
# rag/pipeline.py
from openai import OpenAI

class HybridRAGPipeline:
    def __init__(
        self,
        dense_retriever: DenseRetriever,
        sparse_retriever: BM25Retriever,
        reranker: Reranker,
        llm_client: OpenAI,
        model: str = "gpt-4o",
    ):
        self.dense = dense_retriever
        self.sparse = sparse_retriever
        self.reranker = reranker
        self.llm = llm_client
        self.model = model

    def retrieve(self, query: str, top_k: int = 5) -> List[dict]:
        dense_results = self.dense.search(query, k=20)
        sparse_results = self.sparse.search(query, k=20)

        fused = reciprocal_rank_fusion([dense_results, sparse_results])
        reranked = self.reranker.rerank(query, fused[:40], top_k=top_k)
        return reranked

    def generate(self, query: str, context_chunks: List[dict]) -> str:
        context = "\n\n---\n\n".join(
            f"[Source: {c['chunk'].metadata.get('source', 'unknown')}]\n{c['chunk'].text}"
            for c in context_chunks
        )
        system_prompt = """You are a helpful assistant. Answer the question using ONLY the provided context.
If the context doesn't contain enough information, say "I don't have enough information to answer this."
Always cite the source document when making claims."""

        response = self.llm.chat.completions.create(
            model=self.model,
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": f"Context:\n{context}\n\nQuestion: {query}"},
            ],
            temperature=0.1,
        )
        return response.choices[0].message.content

    def query(self, question: str) -> dict:
        chunks = self.retrieve(question)
        answer = self.generate(question, chunks)
        return {
            "answer": answer,
            "sources": [c["chunk"].metadata for c in chunks],
            "context_chunks": [c["chunk"].text for c in chunks],
        }
```

---

## Evaluation with Ragas

```python
# eval/evaluate.py
from ragas import evaluate
from ragas.metrics import faithfulness, answer_relevancy, context_recall, context_precision
from datasets import Dataset

def evaluate_rag(pipeline: HybridRAGPipeline, test_cases: list) -> dict:
    results = []
    for tc in test_cases:
        output = pipeline.query(tc["question"])
        results.append({
            "question": tc["question"],
            "answer": output["answer"],
            "contexts": output["context_chunks"],
            "ground_truth": tc["ground_truth"],
        })

    dataset = Dataset.from_list(results)
    scores = evaluate(
        dataset,
        metrics=[faithfulness, answer_relevancy, context_recall, context_precision],
    )
    return scores.to_pandas().to_dict()
```

---

## API Endpoint

```python
# api/routes.py
from fastapi import FastAPI
from pydantic import BaseModel

app = FastAPI()

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
    import time
    t0 = time.perf_counter()
    chunks = pipeline.retrieve(req.question, top_k=req.top_k)
    retrieval_time = (time.perf_counter() - t0) * 1000

    t1 = time.perf_counter()
    answer = pipeline.generate(req.question, chunks)
    gen_time = (time.perf_counter() - t1) * 1000

    return QueryResponse(
        answer=answer,
        sources=[c["chunk"].metadata for c in chunks],
        retrieval_time_ms=retrieval_time,
        generation_time_ms=gen_time,
    )
```

---

## Checklist

- [ ] Document chunking with overlap implemented
- [ ] Dense retriever with normalized embeddings (BGE or similar)
- [ ] BM25 sparse retriever on same corpus
- [ ] RRF fusion combining both ranked lists
- [ ] Cross-encoder reranker reducing to top 5 chunks
- [ ] LLM generation with source citation prompt
- [ ] FastAPI endpoint exposing the pipeline
- [ ] Ragas evaluation on at least 20 test Q&A pairs
- [ ] Benchmark: hybrid vs. dense-only vs. sparse-only recall scores documented

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Retrieval-Augmented Generation for NLP* | Various (arXiv) | The original RAG paper by Lewis et al. — read to understand dense retrieval + generation architecture |
| *Building LLM-Powered Applications* | Valentina Alto | End-to-end guide to building LLM applications including RAG, agents, and deployment |
| *Natural Language Processing with Transformers* | Lewis Tunstall et al. | Embedding models, dense retrieval, and cross-encoder rerankers explained with HuggingFace |
| *Hands-On Large Language Models* | Jay Alammar & Maarten Grootendorst | Practical LLM applications including retrieval-augmented generation with code |
| *AI Engineering* | Chip Huyen | End-to-end AI system design including retrieval, evaluation, and production deployment |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| LangChain RAG Docs | [python.langchain.com/docs/use_cases/question_answering](https://python.langchain.com/docs/use_cases/question_answering/) | Reference implementation of RAG with various retrievers and rerankers |
| LlamaIndex Documentation | [docs.llamaindex.ai](https://docs.llamaindex.ai) | Alternative RAG framework with excellent query decomposition and hybrid search support |
| Ragas Documentation | [docs.ragas.io](https://docs.ragas.io) | Comprehensive guide to RAG evaluation metrics — faithfulness, relevancy, recall |
| Cohere Reranking Blog | [txt.cohere.com/rerank](https://txt.cohere.com/rerank/) | Practical explanation of cross-encoder reranking and when it helps |
| RAG Survey Paper | [arxiv.org/abs/2312.10997](https://arxiv.org/abs/2312.10997) | "Retrieval-Augmented Generation for Large Language Models: A Survey" — comprehensive academic overview |
| BM25 Explained | [en.wikipedia.org/wiki/Okapi_BM25](https://en.wikipedia.org/wiki/Okapi_BM25) | The BM25 formula explained — understand what you're combining with dense search |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Building and Evaluating Advanced RAG* | DeepLearning.AI (free) | Short course on RAG pipeline evaluation, reranking, and query decomposition |
| *LangChain for LLM Application Development* | DeepLearning.AI (free) | Building RAG applications with LangChain |
| *Vector Databases: from Embeddings to Applications* | DeepLearning.AI / Weaviate (free) | Embedding models and vector search as the foundation for RAG |
