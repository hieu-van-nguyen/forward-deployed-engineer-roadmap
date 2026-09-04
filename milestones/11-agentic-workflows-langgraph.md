# Milestone 11 — Agentic Workflows: LangGraph / AutoGen

| Field | Value |
|---|---|
| **Month** | M3 |
| **Weeks** | W11–W12 |
| **Priority** | P1 — Critical |
| **Domain** | Agentic Workflows |
| **Objective** | Construct stateful multi-agent workflows using LangGraph / AutoGen for tool calling and parsing |
| **Key Deliverable** | Multi-agent assistant executing SQL queries & API calls |

**📅 Day-by-day plan:** [Week 11 Schedule](../weeks/week-11-agentic-workflows-langgraph.md) (Days 1–7)

---

## Why This Matters for FDEs

Clients want AI that does things, not just answers questions. Agentic systems can execute SQL queries, call APIs, validate data, and loop until a task is complete. FDEs must build these without the agent going off the rails — stateful control flow, bounded loops, and reliable tool use are the differentiators.

---

## Core Concepts

### ReAct Loop (Reason → Act → Observe)
```
LLM thinks: "I need to get order details"
    │
    ▼
Tool call: get_order(order_id=12345)
    │
    ▼
Tool result: { status: "shipped", tracking: "1Z..." }
    │
    ▼
LLM thinks: "I have the data, now I can answer"
    │
    ▼
Final answer
```

### Why Stateful? (LangGraph)
Agents need memory across tool calls — what they've tried, what failed, what they've found. LangGraph persists state as a typed dict flowing through a directed graph.

---

## LangGraph: Single Agent with Tools

```python
# agents/sql_agent.py
from typing import Annotated, TypedDict, Literal
from langgraph.graph import StateGraph, END
from langgraph.prebuilt import ToolNode
from langchain_openai import ChatOpenAI
from langchain_core.tools import tool
import psycopg2
import json

# --- Define State ---
class AgentState(TypedDict):
    messages: list
    sql_results: list
    error_count: int

# --- Define Tools ---
@tool
def execute_sql(query: str) -> str:
    """Execute a SQL SELECT query against the analytics database.
    Only SELECT queries are allowed for safety.
    Args:
        query: A valid SQL SELECT statement
    Returns:
        JSON string of query results (list of dicts)
    """
    if not query.strip().upper().startswith("SELECT"):
        return "ERROR: Only SELECT queries are permitted"
    try:
        conn = psycopg2.connect("postgresql://readonly:pass@db:5432/analytics")
        cur = conn.cursor()
        cur.execute(query)
        cols = [desc[0] for desc in cur.description]
        rows = [dict(zip(cols, row)) for row in cur.fetchmany(100)]
        conn.close()
        return json.dumps(rows, default=str)
    except Exception as e:
        return f"SQL Error: {str(e)}"

@tool
def call_api(endpoint: str, method: str = "GET", body: str = "") -> str:
    """Call an internal REST API endpoint.
    Args:
        endpoint: Relative path like /orders/12345
        method: HTTP method (GET or POST)
        body: JSON body string for POST requests
    """
    import httpx
    base = "https://internal-api.company.com"
    resp = httpx.request(method, f"{base}{endpoint}", json=json.loads(body) if body else None)
    return resp.text

tools = [execute_sql, call_api]

# --- Build Graph ---
llm = ChatOpenAI(model="gpt-4o", temperature=0).bind_tools(tools)

def call_llm(state: AgentState) -> dict:
    response = llm.invoke(state["messages"])
    return {"messages": state["messages"] + [response]}

def should_continue(state: AgentState) -> Literal["tools", "end"]:
    last_msg = state["messages"][-1]
    if hasattr(last_msg, "tool_calls") and last_msg.tool_calls:
        return "tools"
    return "end"

tool_node = ToolNode(tools)

graph = StateGraph(AgentState)
graph.add_node("llm", call_llm)
graph.add_node("tools", tool_node)
graph.set_entry_point("llm")
graph.add_conditional_edges("llm", should_continue, {"tools": "tools", "end": END})
graph.add_edge("tools", "llm")  # After tools, go back to LLM

agent = graph.compile()

# --- Run ---
result = agent.invoke({
    "messages": [
        {"role": "system", "content": "You are a data analyst assistant. Use SQL to answer questions about orders and customers. Always validate your results before presenting."},
        {"role": "user", "content": "What are the top 5 products by revenue in Q1 2024?"}
    ],
    "sql_results": [],
    "error_count": 0,
})
print(result["messages"][-1].content)
```

---

## Multi-Agent System

```python
# agents/multi_agent.py
from langgraph.graph import StateGraph, END
from typing import TypedDict, List, Optional

class WorkflowState(TypedDict):
    user_request: str
    plan: Optional[str]
    data_retrieved: List[dict]
    analysis: Optional[str]
    report: Optional[str]
    iteration: int
    max_iterations: int

# Agent 1: Planner — breaks down the request
def planner_agent(state: WorkflowState) -> dict:
    llm = ChatOpenAI(model="gpt-4o", temperature=0)
    plan = llm.invoke([
        {"role": "system", "content": "You are a planning agent. Given a user request, create a step-by-step data retrieval plan using available tools: execute_sql, call_api."},
        {"role": "user", "content": state["user_request"]},
    ])
    return {"plan": plan.content, "iteration": state["iteration"] + 1}

# Agent 2: Data Retriever — executes the plan
def retriever_agent(state: WorkflowState) -> dict:
    llm = ChatOpenAI(model="gpt-4o", temperature=0).bind_tools([execute_sql, call_api])
    result = llm.invoke([
        {"role": "system", "content": "You are a data retrieval agent. Execute the plan using the provided tools."},
        {"role": "user", "content": f"Plan:\n{state['plan']}\n\nExecute this plan and collect all required data."},
    ])
    # In production, handle tool call loop here
    return {"data_retrieved": [{"raw": result.content}]}

# Agent 3: Analyst — interprets the data
def analyst_agent(state: WorkflowState) -> dict:
    llm = ChatOpenAI(model="gpt-4o", temperature=0)
    analysis = llm.invoke([
        {"role": "system", "content": "You are a data analyst. Interpret the retrieved data and draw business insights."},
        {"role": "user", "content": f"Data:\n{state['data_retrieved']}\n\nProvide a concise business analysis."},
    ])
    return {"analysis": analysis.content}

# Agent 4: Report Writer
def report_writer_agent(state: WorkflowState) -> dict:
    llm = ChatOpenAI(model="gpt-4o", temperature=0)
    report = llm.invoke([
        {"role": "system", "content": "You are a report writer. Create a clear, executive-ready summary."},
        {"role": "user", "content": f"Analysis:\n{state['analysis']}\n\nWrite a concise business report."},
    ])
    return {"report": report.content}

def should_retry(state: WorkflowState) -> str:
    """Route to end or back to planner if iteration limit not reached."""
    if state["iteration"] >= state["max_iterations"]:
        return "end"
    if not state.get("data_retrieved"):
        return "planner"  # Retry if no data
    return "analyst"

# Build multi-agent graph
workflow = StateGraph(WorkflowState)
workflow.add_node("planner", planner_agent)
workflow.add_node("retriever", retriever_agent)
workflow.add_node("analyst", analyst_agent)
workflow.add_node("reporter", report_writer_agent)

workflow.set_entry_point("planner")
workflow.add_edge("planner", "retriever")
workflow.add_conditional_edges("retriever", should_retry, {
    "analyst": "analyst",
    "planner": "planner",
    "end": END,
})
workflow.add_edge("analyst", "reporter")
workflow.add_edge("reporter", END)

multi_agent = workflow.compile()
```

---

## AutoGen Alternative

```python
# agents/autogen_example.py
import autogen

config_list = [{"model": "gpt-4o", "api_key": "sk-..."}]

# Define agents
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
    human_input_mode="NEVER",  # Autonomous
    max_consecutive_auto_reply=10,
    code_execution_config={
        "work_dir": "/tmp/autogen",
        "use_docker": False,
    },
    is_termination_msg=lambda x: "FINAL REPORT" in x.get("content", ""),
)

# Group chat with round-robin or custom speaker selection
groupchat = autogen.GroupChat(
    agents=[human_proxy, planner, executor],
    messages=[],
    max_round=15,
    speaker_selection_method="round_robin",
)
manager = autogen.GroupChatManager(groupchat=groupchat, llm_config={"config_list": config_list})

# Run
human_proxy.initiate_chat(
    manager,
    message="Analyze our top 10 customers by LTV for Q1 2024 and identify churn risk factors."
)
```

---

## Guard Rails & Safety

```python
# Safety: bounded loops, tool whitelisting, query validation
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

---

## Checklist

- [ ] Single-agent ReAct loop with `execute_sql` and `call_api` tools
- [ ] Safety: SQL restricted to SELECT only
- [ ] Multi-agent graph: Planner → Retriever → Analyst → Reporter
- [ ] State persisted across nodes (TypedDict)
- [ ] Max iteration guard to prevent infinite loops
- [ ] Demo: "What are our top 5 products by revenue?" answered end-to-end
- [ ] Demo: "Fetch order #12345 details" using API tool
- [ ] Streaming responses enabled for long-running workflows

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Building LLM-Powered Applications* | Valentina Alto | Covers agentic architectures, tool use, and multi-agent orchestration with LangChain and LangGraph |
| *Hands-On Large Language Models* | Jay Alammar & Maarten Grootendorst | Practical LLM engineering including tool calling, structured output, and agent patterns |
| *AI Engineering* | Chip Huyen | End-to-end AI system design — covers agent reliability, observability, and production trade-offs |
| *The Alignment Problem* | Brian Christian | Background reading on why agent safety and bounded behavior matters in deployed systems |
| *Artificial Intelligence: A Modern Approach* | Russell & Norvig | Foundational AI — rational agents, planning, and decision-making theory |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| LangGraph Documentation | [langchain-ai.github.io/langgraph](https://langchain-ai.github.io/langgraph/) | Official LangGraph docs — state graphs, conditional edges, persistence, and multi-agent patterns |
| AutoGen Documentation | [microsoft.github.io/autogen](https://microsoft.github.io/autogen/) | Microsoft AutoGen docs — conversational agents, group chat, and code execution |
| OpenAI Function Calling Guide | [platform.openai.com/docs/guides/function-calling](https://platform.openai.com/docs/guides/function-calling) | Official reference for tool/function calling with structured tool schemas |
| LangSmith Tracing | [docs.smith.langchain.com](https://docs.smith.langchain.com) | Debugging and observability for LangChain/LangGraph agents |
| ReAct Paper | [arxiv.org/abs/2210.03629](https://arxiv.org/abs/2210.03629) | Original ReAct paper — Reason+Act prompting that underlies most modern agents |
| Anthropic Agent Cookbook | [github.com/anthropics/anthropic-cookbook](https://github.com/anthropics/anthropic-cookbook) | Practical agent patterns using Claude — tool use, computer use, and multi-agent systems |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *AI Agents in LangGraph* | DeepLearning.AI (free) | Short course on building stateful agents with LangGraph by Harrison Chase |
| *Building Agentic AI Systems* | DeepLearning.AI / AutoGen (free) | Multi-agent system design with Microsoft AutoGen |
| *Functions, Tools and Agents with LangChain* | DeepLearning.AI (free) | Tool calling, OpenAI functions, and LangChain agent patterns |
