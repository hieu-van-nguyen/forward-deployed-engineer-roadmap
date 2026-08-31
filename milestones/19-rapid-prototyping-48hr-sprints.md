# Milestone 19 — Rapid Prototyping: 48-Hour Sprints

| Field | Value |
|---|---|
| **Month** | M5 |
| **Weeks** | W19–W20 |
| **Priority** | P2 — High |
| **Domain** | Rapid Prototyping |
| **Objective** | Execute 48-hour sprints to build full-stack vertical slices (Streamlit/Next.js + FastAPI) |
| **Key Deliverable** | 2 end-to-end working MVP prototypes built under tight deadlines |

> **Note:** The source PDF says "FastEngine" — this likely refers to **FastAPI**, the Python async web framework. The content below uses FastAPI accordingly.

---

## Why This Matters for FDEs

Clients don't buy promises; they buy demos. An FDE who can sit in a discovery call Monday and demo a working prototype Wednesday earns trust faster than any slide deck. 48-hour sprints are how FDEs prove concepts before clients commit budgets.

---

## The 48-Hour Sprint Framework

### Hour-by-Hour Plan

| Hours | Phase | Activity |
|-------|-------|---------|
| 0-2 | **Scope** | Write the 1-sentence problem statement. List 3 must-haves, 3 nice-to-haves. Draw the data flow on paper. |
| 2-4 | **Data** | Get sample data. Mock what you don't have. Define the schema. |
| 4-16 | **Backend** | FastAPI + core logic. Get an endpoint returning real data. |
| 16-32 | **Frontend** | Streamlit or Next.js. Wire to backend. Get it ugly but working. |
| 32-40 | **Polish** | Error handling. Loading states. 3 demo scenarios that work 100%. |
| 40-48 | **Demo prep** | Record a video backup. Write the script. Practice the live demo 3 times. |

### The 3-Demo-Scenario Rule
Before any client demo, define exactly 3 scenarios:
1. **Happy path:** The ideal case that shows the value proposition
2. **Edge case:** A tricky input that shows robustness
3. **Failure case:** Show graceful degradation (not a crash)

---

## Streamlit MVP Template

```python
# app.py — RAG chatbot MVP (deployable in ~2 hours)
import streamlit as st
from openai import OpenAI
import time

# ── Config ────────────────────────────────────────────────────────────────
st.set_page_config(
    page_title="Enterprise Knowledge Assistant",
    page_icon="🤖",
    layout="wide",
)

client = OpenAI()

# ── Session State ──────────────────────────────────────────────────────────
if "messages" not in st.session_state:
    st.session_state.messages = []
if "sources" not in st.session_state:
    st.session_state.sources = []

# ── Sidebar ────────────────────────────────────────────────────────────────
with st.sidebar:
    st.title("⚙️ Settings")
    model = st.selectbox("Model", ["gpt-4o", "gpt-4o-mini"])
    top_k = st.slider("Context chunks", 3, 10, 5)
    confidence_threshold = st.slider("Min confidence", 0.0, 1.0, 0.7)
    st.divider()
    if st.button("Clear conversation"):
        st.session_state.messages = []
        st.rerun()

# ── Main ───────────────────────────────────────────────────────────────────
st.title("🤖 Enterprise Knowledge Assistant")
st.caption("Ask questions about your company's policies, products, and procedures.")

# Display chat history
for msg in st.session_state.messages:
    with st.chat_message(msg["role"]):
        st.markdown(msg["content"])
        if msg["role"] == "assistant" and msg.get("sources"):
            with st.expander("📚 Sources"):
                for src in msg["sources"]:
                    st.caption(f"• {src}")

# Chat input
if prompt := st.chat_input("Ask anything..."):
    st.session_state.messages.append({"role": "user", "content": prompt})
    with st.chat_message("user"):
        st.markdown(prompt)

    with st.chat_message("assistant"):
        with st.spinner("Searching knowledge base..."):
            # Call your RAG API
            import httpx
            response = httpx.post(
                "http://localhost:8000/query",
                json={"question": prompt, "top_k": top_k},
                timeout=30.0,
            )
            data = response.json()

        # Stream the answer
        answer_placeholder = st.empty()
        full_answer = ""

        # Simulate streaming if your API doesn't stream
        words = data["answer"].split()
        for i, word in enumerate(words):
            full_answer += word + " "
            answer_placeholder.markdown(full_answer + "▌")
            time.sleep(0.02)
        answer_placeholder.markdown(full_answer)

        # Show sources
        if data.get("sources"):
            with st.expander("📚 Sources"):
                for src in data["sources"]:
                    st.caption(f"• {src.get('source', 'Unknown')}")

    st.session_state.messages.append({
        "role": "assistant",
        "content": data["answer"],
        "sources": data.get("sources", []),
    })
```

---

## FastAPI Backend Template

```python
# api/main.py — Production-ready FastAPI structure
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
from contextlib import asynccontextmanager
import logging

logger = logging.getLogger(__name__)

# ── Lifespan (initialize resources) ────────────────────────────────────────
@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("Starting up: loading models and indexes...")
    # Initialize heavy resources once
    app.state.rag_pipeline = await init_rag_pipeline()
    app.state.vector_index = await load_vector_index()
    logger.info("Ready.")
    yield
    # Cleanup
    logger.info("Shutting down.")

app = FastAPI(
    title="Enterprise AI API",
    version="1.0.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:8501"],  # Streamlit
    allow_methods=["*"],
    allow_headers=["*"],
)

# ── Models ─────────────────────────────────────────────────────────────────
class QueryRequest(BaseModel):
    question: str = Field(..., min_length=3, max_length=1000)
    top_k: int = Field(5, ge=1, le=20)
    filters: dict = Field(default_factory=dict)

class Source(BaseModel):
    source: str
    page: int | None = None
    score: float

class QueryResponse(BaseModel):
    answer: str
    sources: list[Source]
    retrieval_time_ms: float
    generation_time_ms: float
    tokens_used: int
    cost_usd: float

# ── Routes ──────────────────────────────────────────────────────────────────
@app.get("/health")
async def health():
    return {"status": "ok", "version": "1.0.0"}

@app.post("/query", response_model=QueryResponse)
async def query(req: QueryRequest):
    import time
    try:
        pipeline = app.state.rag_pipeline
        t0 = time.perf_counter()
        chunks = await pipeline.retrieve(req.question, top_k=req.top_k)
        retrieval_time = (time.perf_counter() - t0) * 1000

        t1 = time.perf_counter()
        result = await pipeline.generate(req.question, chunks)
        gen_time = (time.perf_counter() - t1) * 1000

        return QueryResponse(
            answer=result.answer,
            sources=[Source(**s) for s in result.sources],
            retrieval_time_ms=round(retrieval_time, 2),
            generation_time_ms=round(gen_time, 2),
            tokens_used=result.tokens_used,
            cost_usd=result.cost_usd,
        )
    except Exception as e:
        logger.exception("Query failed")
        raise HTTPException(500, f"Query processing failed: {str(e)}")

@app.post("/ingest")
async def ingest(file: UploadFile, background_tasks: BackgroundTasks):
    """Async document ingestion — returns immediately, processes in background."""
    content = await file.read()
    background_tasks.add_task(
        process_document,
        content=content,
        filename=file.filename,
    )
    return {"status": "processing", "filename": file.filename}
```

---

## Next.js Frontend Template (Alternative to Streamlit)

```typescript
// app/page.tsx — Next.js RAG chat UI
"use client";
import { useState, useRef, useEffect } from "react";

interface Message {
  role: "user" | "assistant";
  content: string;
  sources?: Array<{ source: string; score: number }>;
}

export default function ChatPage() {
  const [messages, setMessages] = useState<Message[]>([]);
  const [input, setInput] = useState("");
  const [loading, setLoading] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages]);

  async function sendMessage() {
    if (!input.trim() || loading) return;
    const question = input.trim();
    setInput("");
    setMessages((prev) => [...prev, { role: "user", content: question }]);
    setLoading(true);

    try {
      const res = await fetch("/api/query", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ question, top_k: 5 }),
      });
      const data = await res.json();
      setMessages((prev) => [
        ...prev,
        { role: "assistant", content: data.answer, sources: data.sources },
      ]);
    } catch (err) {
      setMessages((prev) => [
        ...prev,
        { role: "assistant", content: "Error: Could not fetch response." },
      ]);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex flex-col h-screen max-w-3xl mx-auto p-4">
      <h1 className="text-2xl font-bold mb-4">Enterprise AI Assistant</h1>
      <div className="flex-1 overflow-y-auto space-y-4 mb-4">
        {messages.map((msg, i) => (
          <div key={i} className={`flex ${msg.role === "user" ? "justify-end" : "justify-start"}`}>
            <div className={`max-w-prose p-3 rounded-lg ${
              msg.role === "user" ? "bg-blue-500 text-white" : "bg-gray-100"
            }`}>
              <p>{msg.content}</p>
              {msg.sources && (
                <details className="mt-2 text-sm opacity-70">
                  <summary>Sources</summary>
                  {msg.sources.map((s, j) => (
                    <p key={j}>• {s.source} ({(s.score * 100).toFixed(0)}%)</p>
                  ))}
                </details>
              )}
            </div>
          </div>
        ))}
        {loading && <div className="text-gray-400">Thinking...</div>}
        <div ref={bottomRef} />
      </div>
      <div className="flex gap-2">
        <input
          className="flex-1 border rounded-lg p-2"
          value={input}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={(e) => e.key === "Enter" && sendMessage()}
          placeholder="Ask anything..."
          disabled={loading}
        />
        <button
          className="bg-blue-500 text-white px-4 rounded-lg disabled:opacity-50"
          onClick={sendMessage}
          disabled={loading}
        >
          Send
        </button>
      </div>
    </div>
  );
}
```

---

## 48-Hour Sprint Prototypes to Build

### Prototype 1: Policy Q&A Assistant
- **Frontend:** Streamlit
- **Backend:** FastAPI + RAG over company policy PDFs
- **Demo scenario:** "What is our expense reimbursement limit for flights?"

### Prototype 2: Customer Data Analyst Agent
- **Frontend:** Next.js chat interface
- **Backend:** FastAPI + LangGraph agent with `execute_sql` tool
- **Demo scenario:** "Who are our top 10 customers by LTV this quarter?"

---

## Checklist

- [ ] Prototype 1 complete: Streamlit + FastAPI, working end-to-end
- [ ] Prototype 2 complete: Next.js + FastAPI, working end-to-end
- [ ] Each prototype demoed with 3 pre-planned scenarios
- [ ] Video backup recording made before each live demo
- [ ] Demo runs without touching the keyboard mid-presentation
- [ ] Error messages are user-friendly (not Python tracebacks)
- [ ] Both repos have a `README.md` with setup instructions

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Sprint* | Jake Knapp (Google Ventures) | The 5-day sprint framework for rapid prototyping — condense to 48 hours for FDE client demos |
| *The Lean Startup* | Eric Ries | Build-Measure-Learn loop and MVP methodology — foundation for rapid FDE prototyping philosophy |
| *Shape Up* | Ryan Singer (free) | Fixed-time, variable-scope development — directly applicable to 48-hour sprint constraints |
| *Continuous Delivery* | Jez Humble & David Farley | Fast deployment pipelines — enables same-day iteration during client sprints |
| *Designing Data-Intensive Applications* | Martin Kleppmann | Architecture patterns for rapid integration — pick the right data layer for a 48-hour build |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Streamlit Documentation | [docs.streamlit.io](https://docs.streamlit.io) | Complete reference for building data apps in minutes — fastest path from Python to shareable UI |
| FastAPI Documentation | [fastapi.tiangolo.com](https://fastapi.tiangolo.com) | Auto-generated OpenAPI docs, async support, and Pydantic integration for rapid API development |
| Next.js Documentation | [nextjs.org/docs](https://nextjs.org/docs) | App router, streaming, and server components — for client-facing chat interfaces |
| Vercel AI SDK | [sdk.vercel.ai](https://sdk.vercel.ai) | Streaming LLM responses in Next.js — `useChat` hook for instant chat UI |
| LangChain Quickstart | [python.langchain.com/docs/get_started/quickstart](https://python.langchain.com/docs/get_started/quickstart/) | 15-minute RAG prototype — the fastest path to a working AI demo |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Building Systems with the ChatGPT API* | DeepLearning.AI (free) | Rapid API integration, chaining calls, and building functional AI demos quickly |
| *Full-Stack FastAPI and React Guide* | Udemy (various) | End-to-end prototype in FastAPI + React — applicable to 48-hour sprint frontend needs |
| *Streamlit for Data Science* | YouTube / DataCamp | Building shareable AI apps in under 1 hour — ideal for FDE demo preparation |
