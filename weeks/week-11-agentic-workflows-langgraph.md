# Week 11 — Agentic Workflows: LangGraph / AutoGen — Day-by-Day Plan

> **Milestone:** [11 — Agentic Workflows: LangGraph / AutoGen](../milestones/11-agentic-workflows-langgraph.md)
> **Month:** M3 · **Weeks:** W11–W12 (this plan covers W11, Days 1–7)
> **Pacing note:** The milestone spans W11–W12. This document covers W11 (single-agent ReAct + multi-agent LangGraph, fixed). W12 is covered by [Milestone 12 — LLM Guardrails: Structured Output](../milestones/12-llm-guardrails-structured-output.md).
> **Deliverable:** A multi-agent assistant that actually executes SQL queries and API calls end-to-end, with a real bounded-loop guard — not one that merely looks bounded.

> **⚠️ Scope reality check before Day 1:**
> - **`https://internal-api.company.com` resolves nowhere.** Stand up a local FastAPI stub for `call_api` to hit instead — same pattern as Week 4's resilient HTTP client work.
> - **`postgresql://readonly:pass@db:5432/analytics` doesn't exist yet.** You need a local Postgres with a seeded `orders`/`products` schema (Q1 2024 rows, since that's what the checklist's demo question asks about) and a genuinely read-only role — `readonly:pass` implies a role that isn't actually restricted to `SELECT` at the database level.
> - **This week unavoidably needs a real OpenAI (or equivalent) API key.** Tool-calling agents are the subject matter — there's no meaningful local-model substitute for `.bind_tools()` behavior this week (unlike Week 9's Pinecone or Week 10's Ragas, which had key-free fallback paths). Set a hard cost cap and use `gpt-4o-mini` for iteration; save `gpt-4o` for final demo runs.

---

## Overview

| Day | Focus | Output |
|-----|-------|--------|
| 1 | Environment: local analytics DB + mock internal API | Seeded Postgres (read-only role) + FastAPI stub running locally |
| 2 | Single-agent ReAct loop — fix the state-accumulation bug | Working `execute_sql`/`call_api` agent with `add_messages` reducer |
| 3 | The *real* loop guard — `recursion_limit` vs. `SafeAgentWrapper` | Bounded agent that fails gracefully instead of raising `GraphRecursionError` |
| 4 | Multi-agent graph — fix the dead retry path | Planner → Retriever → Analyst → Reporter with retriever that can actually signal failure |
| 5 | Guardrails: SQL validation hardening + API timeouts | Belt-and-braces SELECT-only enforcement, `httpx` timeouts, tool whitelisting |
| 6 | AutoGen — comparison day (stretch), not a rebuild | AutoGen group chat run once, compared against LangGraph on the same task |
| 7 | End-to-end demo + streaming | Both checklist demos working, streaming enabled, written LangGraph-vs-AutoGen note |

---

## Day 1 — Environment: Local Analytics DB + Mock Internal API

**Goal:** Before writing any agent code, make the two tools (`execute_sql`, `call_api`) point at something real and local — not a hostname that doesn't resolve and a database role that doesn't exist.

### Seeded Postgres With a Genuinely Read-Only Role

```sql
-- init.sql
CREATE TABLE products (id SERIAL PRIMARY KEY, name TEXT, category TEXT);
CREATE TABLE orders (
    id SERIAL PRIMARY KEY,
    product_id INT REFERENCES products(id),
    revenue NUMERIC,
    order_date DATE
);

INSERT INTO products (name, category) VALUES
    ('Widget A', 'hardware'), ('Widget B', 'hardware'), ('Gadget X', 'electronics'),
    ('Gadget Y', 'electronics'), ('Doohickey', 'misc'), ('Thingamajig', 'misc');

-- Seed enough Q1 2024 rows that "top 5 products by revenue" has a real answer
INSERT INTO orders (product_id, revenue, order_date)
SELECT (random() * 5 + 1)::int, (random() * 500 + 10)::numeric(10,2),
       ('2024-01-01'::date + (random() * 89)::int)
FROM generate_series(1, 5000);

-- The role the milestone's connection string implies but never actually creates
CREATE ROLE readonly WITH LOGIN PASSWORD 'pass';
GRANT CONNECT ON DATABASE analytics TO readonly;
GRANT USAGE ON SCHEMA public TO readonly;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO readonly;  -- read-only enforced by Postgres, not just app code
```

```yaml
# docker-compose.yml addition
services:
  analytics-db:
    image: postgres:16
    environment:
      POSTGRES_DB: analytics
      POSTGRES_USER: app
      POSTGRES_PASSWORD: adminpass
    ports: ["5434:5432"]
    volumes:
      - ./init.sql:/docker-entrypoint-initdb.d/init.sql
```

### Mock Internal API

```python
# mock_internal_api.py — stands in for https://internal-api.company.com
from fastapi import FastAPI
import uvicorn

app = FastAPI()

ORDERS_DB = {
    "12345": {"status": "shipped", "tracking": "1Z999AA10123456784", "product": "Widget A"},
}

@app.get("/orders/{order_id}")
def get_order(order_id: str):
    return ORDERS_DB.get(order_id, {"error": "not found"})

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8080)
```

```bash
docker compose up -d analytics-db
python mock_internal_api.py &   # localhost:8080, replaces the unreachable internal-api.company.com
psql postgresql://readonly:pass@localhost:5434/analytics -c "SELECT count(*) FROM orders;"
# Expect 5000 — and try an INSERT as `readonly` to confirm it's actually rejected at the DB level
psql postgresql://readonly:pass@localhost:5434/analytics -c "INSERT INTO products (name) VALUES ('x');"
# Expect: ERROR: permission denied for table products
```

### Done when
- [ ] `analytics` Postgres running locally with seeded `orders`/`products`, Q1 2024 dates present
- [ ] `readonly` role confirmed to reject writes at the database level (tested, not assumed)
- [ ] Mock API running on `localhost:8080`, `call_api`'s base URL pointed at it instead of the unreachable hostname

---

## Day 2 — Single-Agent ReAct Loop: Fix the State-Accumulation Bug

**Goal:** Build the single-agent LangGraph loop, but fix a bug in how message state accumulates across the `llm ↔ tools` cycle before trusting any of its output.

### The Bug: `messages: list` Has No Reducer, So Tool Results Overwrite History

```python
class AgentState(TypedDict):
    messages: list          # plain list — LangGraph REPLACES this key on every node return
    sql_results: list
    error_count: int

def call_llm(state: AgentState) -> dict:
    response = llm.invoke(state["messages"])
    return {"messages": state["messages"] + [response]}   # manually re-appends full history — works here...
```

...but `ToolNode` (the prebuilt node handling tool execution) returns `{"messages": [tool_message]}` — just the *new* `ToolMessage`, assuming LangGraph will *merge* it into existing history via a reducer. Without one, LangGraph's default behavior is to replace `state["messages"]` with whatever the node returns — so after a tool call, the entire prior conversation (the user's question, the LLM's tool-call request) is silently dropped, and the LLM's next turn sees only the tool's raw output with no memory of what was asked.

### The Fix — `Annotated[list, add_messages]` (Already Imported, Never Used)

```python
# agents/sql_agent.py — fixed
from typing import Annotated, TypedDict, Literal
from langgraph.graph import StateGraph, END
from langgraph.graph.message import add_messages
from langgraph.prebuilt import ToolNode
from langchain_openai import ChatOpenAI
from langchain_core.tools import tool
import psycopg2, json, os

class AgentState(TypedDict):
    messages: Annotated[list, add_messages]   # reducer: appends instead of replacing
    sql_results: list
    error_count: int

@tool
def execute_sql(query: str) -> str:
    """Execute a SQL SELECT query against the analytics database. Only SELECT queries are permitted."""
    q = query.strip()
    if not q.upper().startswith("SELECT") or ";" in q[:-1]:   # reject stacked statements too — Day 5 hardens this further
        return "ERROR: Only single SELECT queries are permitted"
    try:
        conn = psycopg2.connect(os.environ["ANALYTICS_DB_URL"])  # readonly role from Day 1, not hardcoded creds
        cur = conn.cursor()
        cur.execute(q)
        cols = [desc[0] for desc in cur.description]
        rows = [dict(zip(cols, row)) for row in cur.fetchmany(100)]
        conn.close()
        return json.dumps(rows, default=str)
    except Exception as e:
        return f"SQL Error: {str(e)}"

@tool
def call_api(endpoint: str, method: str = "GET", body: str = "") -> str:
    """Call the internal REST API (local mock this week)."""
    import httpx
    base = os.environ.get("INTERNAL_API_BASE", "http://localhost:8080")  # Day 1's mock, not the dead hostname
    resp = httpx.request(method, f"{base}{endpoint}",
                          json=json.loads(body) if body else None,
                          timeout=10)   # unbounded requests can hang a whole agent run — always set this
    return resp.text

tools = [execute_sql, call_api]
llm = ChatOpenAI(model="gpt-4o-mini", temperature=0).bind_tools(tools)  # mini for iteration, cost cap

def call_llm(state: AgentState) -> dict:
    response = llm.invoke(state["messages"])
    return {"messages": [response]}   # return only the delta — the reducer handles appending now

def should_continue(state: AgentState) -> Literal["tools", "end"]:
    last_msg = state["messages"][-1]
    return "tools" if getattr(last_msg, "tool_calls", None) else "end"

graph = StateGraph(AgentState)
graph.add_node("llm", call_llm)
graph.add_node("tools", ToolNode(tools))
graph.set_entry_point("llm")
graph.add_conditional_edges("llm", should_continue, {"tools": "tools", "end": END})
graph.add_edge("tools", "llm")
agent = graph.compile()
```

### Verification — Prove the History Actually Persists

```python
result = agent.invoke({
    "messages": [
        {"role": "system", "content": "You are a data analyst assistant. Use SQL to answer questions."},
        {"role": "user", "content": "What are the top 5 products by revenue in Q1 2024?"},
    ],
    "sql_results": [], "error_count": 0,
})
print(len(result["messages"]))   # should be > 2 (system + user + tool-call + tool-result + final answer),
                                  # not 1 (which is what the un-fixed version would collapse to after a tool call)
print(result["messages"][-1].content)
```

### Done when
- [ ] `AgentState.messages` uses `Annotated[list, add_messages]`
- [ ] `call_llm` returns only `{"messages": [response]}`, not the full re-concatenated list
- [ ] Verified: after a tool call, `result["messages"]` still contains the original system/user messages — printed and inspected, not assumed
- [ ] `execute_sql` connects via `os.environ["ANALYTICS_DB_URL"]` (Day 1's readonly role), no hardcoded credentials
- [ ] `call_api` has an explicit `timeout=10`, points at Day 1's mock

---

## Day 3 — The *Real* Loop Guard: `recursion_limit` vs. `SafeAgentWrapper`

**Goal:** Understand why the milestone's `SafeAgentWrapper` doesn't actually prevent an infinite loop the way it appears to, and use LangGraph's actual mechanism instead.

### The Bug: `SafeAgentWrapper` Has Two Separate Problems

```python
class SafeAgentWrapper:
    def __init__(self, agent, max_tool_calls: int = 20):
        self.agent = agent
        self.max_tool_calls = max_tool_calls
        self._tool_call_count = 0

    def invoke(self, state: dict) -> dict:
        self._tool_call_count = 0
        for event in self.agent.stream(state):
            if "tools" in event:
                self._tool_call_count += 1
                if self._tool_call_count > self.max_tool_calls:
                    return {"error": "Max tool calls exceeded — possible infinite loop"}
        return event
```

1. **It never actually stops the agent.** Checking the loop-count inside the `for` loop only decides what `invoke()` *returns* — the underlying `self.agent.stream(state)` generator keeps running regardless, consuming LLM calls and tool calls until LangGraph's own internal cycle limit kicks in (see below), or until the graph naturally reaches `END`.
2. **`return event` returns the last *streamed delta*, not the accumulated final state.** With `stream_mode="updates"` (the default), each `event` is just `{"llm": {...}}` or `{"tools": {...}}` — the output of whichever node last ran, not the full merged `AgentState`. Callers expecting `result["messages"][-1]` to be the final answer get an arbitrary partial update instead. And if `self.agent.stream(state)` yields zero events (an edge case, but possible on a malformed graph), `event` is referenced unbound at `return event`.

The actual mechanism that stops an `llm ↔ tools` cycle is LangGraph's `recursion_limit` — and hitting it raises `GraphRecursionError`, not a graceful `{"error": ...}` dict.

### The Fix — Set `recursion_limit`, Catch the Exception, Track `error_count` for Real

```python
from langgraph.errors import GraphRecursionError

def run_agent_safely(agent, initial_state: dict, max_steps: int = 15) -> dict:
    try:
        return agent.invoke(initial_state, config={"recursion_limit": max_steps})
    except GraphRecursionError:
        return {
            "messages": initial_state["messages"],
            "error": f"Exceeded {max_steps} steps — possible infinite loop or unresolvable request",
        }

result = run_agent_safely(agent, {
    "messages": [...], "sql_results": [], "error_count": 0,
}, max_steps=15)
```

Also wire `error_count` into `execute_sql`'s error path so it's not a dead field in `AgentState`:

```python
def should_continue(state: AgentState) -> Literal["tools", "end"]:
    last_msg = state["messages"][-1]
    if state.get("error_count", 0) >= 3:
        return "end"   # give up after repeated tool failures, not just after N total steps
    return "tools" if getattr(last_msg, "tool_calls", None) else "end"
```

### Verification — Force the Limit and Watch It Fail Gracefully

```python
# Ask something the agent can't resolve (nonexistent table) and confirm graceful handling
result = run_agent_safely(agent, {
    "messages": [
        {"role": "system", "content": "You are a data analyst. Use SQL to answer questions."},
        {"role": "user", "content": "Query the nonexistent_table_xyz for anything useful, keep trying different approaches until it works."},
    ],
    "sql_results": [], "error_count": 0,
}, max_steps=8)
print(result.get("error"))   # should print the graceful message, not a raw traceback
```

### Done when
- [ ] Can explain, unprompted, why `SafeAgentWrapper.invoke()` as originally written doesn't stop the agent early
- [ ] `run_agent_safely()` uses `config={"recursion_limit": ...}` and catches `GraphRecursionError`
- [ ] Forced a runaway scenario and confirmed a graceful `{"error": ...}` result, not an unhandled exception
- [ ] `error_count` actually read somewhere in graph logic, not a dead `AgentState` field

---

## Day 4 — Multi-Agent Graph: Fix the Dead Retry Path

**Goal:** Build the Planner → Retriever → Analyst → Reporter graph, but fix a bug where the retry-on-failure branch can never actually trigger.

### The Bug: `retriever_agent` Always Returns Truthy Data, So Retry Never Fires

```python
def retriever_agent(state: WorkflowState) -> dict:
    llm = ChatOpenAI(model="gpt-4o", temperature=0).bind_tools([execute_sql, call_api])
    result = llm.invoke([...])
    # In production, handle tool call loop here          <- tool calls are never actually executed
    return {"data_retrieved": [{"raw": result.content}]}  # always a non-empty list, success or not

def should_retry(state: WorkflowState) -> str:
    if state["iteration"] >= state["max_iterations"]:
        return "end"
    if not state.get("data_retrieved"):   # this condition can NEVER be true — dead branch
        return "planner"
    return "analyst"
```

Two compounding problems: `retriever_agent` never actually executes the tool calls the LLM requests (the comment admits this is a stub), and even once fixed, it always wraps *something* in a one-element list — so `not state.get("data_retrieved")` is always `False`, and the "retry from planner" path is unreachable dead code regardless of whether real data was fetched.

### The Fix — Actually Execute Tool Calls, Signal Failure Honestly

```python
from langgraph.prebuilt import ToolNode

def retriever_agent(state: WorkflowState) -> dict:
    llm = ChatOpenAI(model="gpt-4o-mini", temperature=0).bind_tools([execute_sql, call_api])
    response = llm.invoke([
        {"role": "system", "content": "You are a data retrieval agent. Execute the plan using the provided tools."},
        {"role": "user", "content": f"Plan:\n{state['plan']}\n\nExecute this plan and collect all required data."},
    ])

    if not getattr(response, "tool_calls", None):
        return {"data_retrieved": []}   # honest failure signal — no tool was actually called

    tool_node = ToolNode([execute_sql, call_api])
    tool_results = tool_node.invoke({"messages": [response]})
    retrieved = [{"raw": m.content} for m in tool_results["messages"]]
    # Treat SQL/API error strings as failures too, not just "no tool call happened"
    if any("ERROR" in r["raw"] or "SQL Error" in r["raw"] for r in retrieved):
        return {"data_retrieved": []}
    return {"data_retrieved": retrieved}

def should_retry(state: WorkflowState) -> str:
    if state["iteration"] >= state["max_iterations"]:
        return "end"
    if not state.get("data_retrieved"):   # now reachable — retriever can genuinely return []
        return "planner"
    return "analyst"
```

### The Fix — Provide the Initial State the Milestone Never Shows

```python
multi_agent = workflow.compile()

result = multi_agent.invoke({
    "user_request": "Analyze top 5 products by revenue in Q1 2024",
    "plan": None,
    "data_retrieved": [],
    "analysis": None,
    "report": None,
    "iteration": 0,          # milestone never shows this — without it, planner_agent's `state["iteration"] + 1` KeyErrors
    "max_iterations": 3,
})
print(result["report"])
```

### Done when
- [ ] `retriever_agent` actually invokes `ToolNode` on the LLM's tool calls — real SQL/API execution, not a stub comment
- [ ] `retriever_agent` returns `{"data_retrieved": []}` on genuine failure (no tool call, or a tool error string)
- [ ] Confirmed the `should_retry` → `"planner"` branch is now reachable — force a failure (bad plan) and watch it loop back, not just read the code and assume
- [ ] Initial state includes `"iteration": 0, "max_iterations": 3` explicitly

---

## Day 5 — Guardrails: Harden SQL Validation and API Safety

**Goal:** The milestone's checklist calls for "Safety: SQL restricted to SELECT only" — go one level past a naive `.startswith("SELECT")` check, since that alone doesn't stop stacked statements or CTEs wrapping writes.

```python
import re

DISALLOWED = re.compile(r"\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|GRANT|CREATE)\b", re.IGNORECASE)

@tool
def execute_sql(query: str) -> str:
    """Execute a single read-only SQL SELECT query."""
    q = query.strip().rstrip(";")
    if not q.upper().startswith("SELECT"):
        return "ERROR: Only SELECT queries are permitted"
    if ";" in q:
        return "ERROR: Multiple statements are not permitted"
    if DISALLOWED.search(q):
        return "ERROR: Query contains a disallowed keyword"  # catches `WITH x AS (INSERT ...) SELECT * FROM x`
    # Belt-and-braces: the readonly DB role from Day 1 is the actual enforcement boundary.
    # This check is defense-in-depth, not the only line of defense.
    try:
        conn = psycopg2.connect(os.environ["ANALYTICS_DB_URL"])
        conn.set_session(readonly=True)   # ask the driver to enforce it too
        ...
```

Also finish the tool-whitelisting idea the milestone gestures at but doesn't implement:

```python
ALLOWED_TOOLS = {"execute_sql", "call_api"}

def validate_tool_call(tool_call: dict):
    if tool_call["name"] not in ALLOWED_TOOLS:
        raise ValueError(f"Tool '{tool_call['name']}' is not in the allowed set")
```

### Done when
- [ ] SQL validator rejects stacked statements (`SELECT 1; DROP TABLE orders;`) and CTE-wrapped writes (`WITH x AS (DELETE FROM orders RETURNING *) SELECT * FROM x`)
- [ ] `conn.set_session(readonly=True)` set as a second enforcement layer on top of Day 1's DB role
- [ ] `call_api` confirmed to time out (tested against a deliberately slow/unreachable endpoint) rather than hang
- [ ] Explicit tool whitelist check in place, even though only two tools exist today — this is what prevents a future added tool from being callable without a deliberate decision

---

## Day 6 — AutoGen: Comparison Day, Not a Rebuild

**Goal:** Run the same task through AutoGen once, for comparison — this is a stretch/comparison day, not a full second implementation. Fix the two concrete issues in the milestone's AutoGen snippet before running it.

### The Bugs: Plaintext API Key, and Sandboxing Turned Off

```python
config_list = [{"model": "gpt-4o", "api_key": "sk-..."}]   # plaintext secret in source — never do this
...
code_execution_config={"work_dir": "/tmp/autogen", "use_docker": False}  # LLM-generated code runs unsandboxed on the host
```

### The Fix

```python
import os
import autogen

config_list = [{"model": "gpt-4o-mini", "api_key": os.environ["OPENAI_API_KEY"]}]

planner = autogen.AssistantAgent(
    name="Planner",
    system_message="You are a planning agent. Break down data requests into specific SQL queries and API calls.",
    llm_config={"config_list": config_list},
)
executor = autogen.AssistantAgent(
    name="Executor",
    system_message="You are a code executor. Run SQL queries and API calls. Report exact results.",
    llm_config={"config_list": config_list},
)
human_proxy = autogen.UserProxyAgent(
    name="UserProxy",
    human_input_mode="NEVER",
    max_consecutive_auto_reply=10,
    code_execution_config={"work_dir": "/tmp/autogen", "use_docker": True},  # sandboxed — requires Docker running locally
    is_termination_msg=lambda x: "FINAL REPORT" in x.get("content", ""),
)

groupchat = autogen.GroupChat(agents=[human_proxy, planner, executor], messages=[], max_round=15,
                               speaker_selection_method="round_robin")
manager = autogen.GroupChatManager(groupchat=groupchat, llm_config={"config_list": config_list})

human_proxy.initiate_chat(manager, message="Analyze top 5 products by revenue in Q1 2024 and identify trends.")
```

> If Docker-in-Docker sandboxing isn't feasible in your environment, running with `use_docker=False` for this one comparison run is acceptable **only** as an explicitly acknowledged, temporary exception — never the default for anything beyond a same-day throwaway test.

### Done when
- [ ] API key loaded from `os.environ`, never hardcoded
- [ ] `use_docker=True` used, or the exception explicitly noted if not feasible locally
- [ ] Same task run through both LangGraph (Day 4) and AutoGen — a few sentences written comparing control-flow explicitness (LangGraph's typed state graph) vs. AutoGen's conversational round-robin

---

## Day 7 — End-to-End Demo + Streaming

**Goal:** Satisfy both checklist demos with the fixed pipeline, and enable streaming for the long-running multi-agent workflow.

```python
# Demo 1: SQL analytics question
result = run_agent_safely(agent, {
    "messages": [
        {"role": "system", "content": "You are a data analyst assistant. Use SQL to answer questions about orders and customers."},
        {"role": "user", "content": "What are the top 5 products by revenue in Q1 2024?"},
    ],
    "sql_results": [], "error_count": 0,
}, max_steps=15)
print(result["messages"][-1].content)

# Demo 2: API tool
result2 = run_agent_safely(agent, {
    "messages": [
        {"role": "system", "content": "You are a data analyst assistant. Use the API tool to look up order details."},
        {"role": "user", "content": "Fetch order #12345 details"},
    ],
    "sql_results": [], "error_count": 0,
}, max_steps=10)
print(result2["messages"][-1].content)
```

### Streaming

```python
for event in agent.stream(
    {"messages": [{"role": "user", "content": "What are the top 5 products by revenue in Q1 2024?"}],
     "sql_results": [], "error_count": 0},
    config={"recursion_limit": 15},
    stream_mode="updates",
):
    for node_name, update in event.items():
        print(f"[{node_name}]", update["messages"][-1].content[:120] if update.get("messages") else update)
```

### Done when
- [ ] Both checklist demo questions produce correct, real (not hallucinated) answers sourced from Day 1's seeded data and mock API
- [ ] Streaming output shows each node's activity as it happens, not just a final blocking result
- [ ] Written note comparing LangGraph vs. AutoGen for this use case, informed by Day 6's side-by-side run

---

## Resources Quick Reference

| Day | Key Resource |
|-----|-------------|
| 1 | [FastAPI docs](https://fastapi.tiangolo.com) |
| 2 | [LangGraph — `add_messages` reducer](https://langchain-ai.github.io/langgraph/concepts/low_level/#reducers) |
| 3 | [LangGraph — recursion limit & errors](https://langchain-ai.github.io/langgraph/reference/errors/) |
| 4 | [LangGraph multi-agent patterns](https://langchain-ai.github.io/langgraph/) |
| 5 | [OWASP — SQL Injection Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html) |
| 6 | [AutoGen Documentation](https://microsoft.github.io/autogen/) |
| 7 | [LangGraph streaming docs](https://langchain-ai.github.io/langgraph/how-tos/streaming/) |

---

*→ Next: [Milestone 12 — LLM Guardrails: Structured Output](../milestones/12-llm-guardrails-structured-output.md)*
