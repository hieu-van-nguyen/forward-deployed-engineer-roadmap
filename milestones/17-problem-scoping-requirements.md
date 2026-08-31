# Milestone 17 — Problem Scoping: Requirements Decomposition

| Field | Value |
|---|---|
| **Month** | M5 |
| **Weeks** | W17–W18 |
| **Priority** | P1 — Critical |
| **Domain** | Problem Scoping |
| **Objective** | Practice live requirements decomposition: convert vague executive business requests into technical RFCs |
| **Key Deliverable** | 3 comprehensive Technical Requirement & Specs |

---

## Why This Matters for FDEs

A VP says: "We want AI to help our sales team." That's it. No specs. No user stories. No technical requirements. FDEs must convert this into a buildable plan in a 30-minute discovery call — and then produce an RFC the engineering team can execute. This is the most-cited differentiator between strong and weak FDE candidates.

---

## The Discovery Framework

### The 5-Layer Decomposition

```
Layer 1: BUSINESS GOAL
  "Increase sales team productivity"
        │
        ▼
Layer 2: MEASURABLE OUTCOME
  "Reduce time to prepare for a customer call by 50%"
        │
        ▼
Layer 3: USER WORKFLOWS
  "Sales rep looks up customer history, deal stage, last interactions"
        │
        ▼
Layer 4: DATA REQUIREMENTS
  "CRM data (Salesforce), email history, call transcripts, product usage"
        │
        ▼
Layer 5: TECHNICAL SOLUTION
  "RAG over Salesforce + email + call transcripts, surfaced in Chrome extension"
```

---

## Discovery Call Question Bank

### Business Context
- "What does success look like for this initiative in 6 months?"
- "How is the team currently solving this problem manually?"
- "What's the cost of NOT solving this?" (quantify pain)
- "Who are the daily users vs. executive stakeholders?"

### Data & Systems
- "What systems store the data we'd need?" (CRM, ERP, data warehouse?)
- "Is the data in good shape, or is cleanup needed first?"
- "Any data privacy / PII constraints we need to know about?" (GDPR, HIPAA?)
- "Who owns data access approvals?"

### Constraints
- "What's the deployment environment?" (SaaS OK? On-prem? Air-gapped?)
- "Are there SSO/auth requirements?"
- "What's the budget for API calls / infrastructure?"
- "Timeline: soft demo vs. hard production deadline?"

### Success Metrics
- "How would you measure if this is working?"
- "Who defines success — the end users or the exec sponsor?"
- "What does a failed deployment look like from your perspective?"

---

## RFC Template

```markdown
# RFC: [Project Name] — [Date]

## Status
- [ ] Draft  - [ ] In Review  - [ ] Approved  - [ ] Implemented

**Author:** [Name]  
**Stakeholders:** [VP Product, Sales Ops, Engineering Lead]  
**Decision date:** [Date]

---

## 1. Executive Summary
One paragraph: what we're building, why, and the expected business outcome.

## 2. Problem Statement

### Business Problem
[Describe the pain in business terms, with quantification where possible]

### Current State
[How is this done today? What are the failure modes?]

### Target State
[What does the world look like after this ships?]

## 3. Success Metrics

| Metric | Current | Target | How Measured |
|--------|---------|--------|-------------|
| Time to prepare for sales call | 45 min | 15 min | User survey + time tracking |
| Customer data retrieval accuracy | — | ≥ 90% | Eval harness (monthly) |
| Adoption rate | — | 80% of team within 60 days | CRM tracking |

## 4. Proposed Solution

### Architecture Overview
[Diagram or description of the system]

### Components

**Component 1: Data Ingestion**
- Sources: Salesforce Opportunities, Gmail (via Google Workspace API), Gong call transcripts
- Frequency: Nightly sync + real-time webhooks for new calls
- Processing: Extract → Chunk → Embed → Store in pgvector

**Component 2: Retrieval & Generation**
- Hybrid RAG: dense (BGE-small) + BM25 + cross-encoder reranker
- LLM: GPT-4o for generation (fallback to GPT-4o-mini for speed)
- Context window: Top 5 chunks (≤ 3000 tokens)

**Component 3: Interface**
- Chrome Extension overlaying Salesforce CRM pages
- Query: "Summarize this account's last 3 months" (one-click)

### Data Flow
```
Salesforce → Nightly sync → ETL → Chunker → Embedder → pgvector
                                                              │
                                                    User query (Chrome ext)
                                                              │
                                                    Hybrid RAG retrieval
                                                              │
                                                    GPT-4o synthesis
                                                              │
                                                    Chrome ext display
```

## 5. Technical Requirements

### Functional
- FR-1: System ingests Salesforce opportunity, contact, and activity data
- FR-2: System retrieves semantically relevant context given a natural language query
- FR-3: System generates a summary citing specific data points
- FR-4: Chrome extension displays results within 5 seconds

### Non-Functional
- NFR-1: p95 end-to-end latency ≤ 5 seconds
- NFR-2: System handles 50 concurrent users
- NFR-3: PII (email addresses, phone numbers) not stored in vector DB
- NFR-4: All data remains within customer's AWS VPC
- NFR-5: SAML SSO via Okta required for authentication

### Out of Scope
- Real-time Salesforce data (nightly sync is acceptable for v1)
- Slack integration (planned for v2)
- Generating outbound emails (legal review required)

## 6. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Salesforce API rate limits | Medium | High | Implement backoff; cache responses |
| Data quality issues in CRM | High | Medium | Add data validation step; alert on anomalies |
| User adoption resistance | Medium | High | Involve sales reps in beta; run training sessions |
| LLM hallucination in summaries | Low | High | Ragas faithfulness eval; citation enforcement |

## 7. Timeline

| Milestone | Week | Owner |
|-----------|------|-------|
| Data ingestion MVP | W2 | Backend |
| RAG pipeline (eval passing) | W3 | AI Eng |
| Chrome extension alpha | W4 | Frontend |
| Beta with 5 users | W5 | FDE |
| Eval threshold met (≥0.85) | W6 | AI Eng |
| Prod launch | W8 | All |

## 8. Open Questions
- [ ] Does Salesforce admin approve API access for nightly sync?
- [ ] Is Gong integration feasible given their API rate limits?
- [ ] Who handles data deletion requests (GDPR)?

## 9. Decision Log
| Date | Decision | Rationale |
|------|---------|-----------|
| 2024-01-10 | pgvector over Pinecone | On-prem requirement; client won't use managed SaaS vector DB |
```

---

## Live Decomposition Practice (3 Exercises)

### Exercise 1: "We want AI for our support team"
Practice decomposing this vague statement into:
- Business metrics (ticket deflection rate, CSAT improvement)
- User workflows (agent lookup, customer self-service)
- Data sources (Zendesk, Confluence, product logs)
- Technical architecture (RAG over knowledge base)

### Exercise 2: "Can AI analyze our contracts?"
Decompose into:
- Specific use cases (risk clause detection, obligation extraction, comparison)
- Data constraints (legal confidentiality, redlines, version history)
- Output format (structured JSON, human-readable summary, flagging dashboard)

### Exercise 3: "We need an AI data analyst"
Decompose into:
- Who's the user? (non-technical exec vs. trained analyst)
- What data? (data warehouse, Excel uploads, live API)
- What questions? (predefined vs. open-ended NL-to-SQL)
- Safety rails (no write operations, PII masking)

---

## Checklist

- [ ] Conduct 3 mock discovery calls (with a peer playing exec)
- [ ] Write 3 full RFCs using the template above
- [ ] Each RFC includes: metrics, architecture, constraints, timeline, risks
- [ ] Each RFC has been reviewed by at least one other person
- [ ] Create a "question bank" of 20+ discovery call questions you've memorized
- [ ] Practice answering: "How long will this take?" with confidence + caveats

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Inspired: How to Create Tech Products Customers Love* | Marty Cagan | Product discovery and requirements elicitation from the source — how to find real problems worth solving |
| *The Mom Test* | Rob Fitzpatrick | How to ask questions that get honest answers — essential for discovery calls with clients who are too polite |
| *Shape Up* | Ryan Singer (Basecamp, free) | Problem scoping methodology — appetite, breadth-first exploration, and defining boundaries before building |
| *Continuous Discovery Habits* | Teresa Torres | Weekly customer touchpoints, opportunity solution trees, and assumption testing |
| *Thinking in Systems* | Donella Meadows | Systems thinking for decomposing complex enterprise problems into components, flows, and feedback loops |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Shape Up (full book) | [basecamp.com/shapeup](https://basecamp.com/shapeup) | Free online book on problem shaping and scoping before committing to implementation |
| JTBD Theory | [jobs-to-be-done.com](https://jobs-to-be-done.com) | Jobs-to-be-Done framework — understand what the client is really trying to accomplish |
| RFC Templates (GitHub) | [github.com/nicowillis/rfc-template](https://github.com/nicowillis/rfc-template) | RFC template references used at major tech companies — adapt for client-facing technical proposals |
| Product Therapy Podcast | [produxlabs.com/product-therapy](https://www.produxlabs.com/product-therapy) | Teresa Torres interviews on discovery and requirement elicitation techniques |
| First Round Review | [review.firstround.com](https://review.firstround.com) | Articles on problem framing, requirements discovery, and scoping at product and engineering level |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *AI Product Management* | Coursera / Duke | Scoping AI projects, defining feasibility, and writing requirements for ML systems |
| *Become a Product Manager* | Udemy (Cole Mercer) | Discovery calls, user research, and requirements documentation techniques |
| *Systems Thinking* | MIT OpenCourseWare (free) | Decomposing complex systems — directly applicable to 5-layer problem decomposition for enterprise AI |
