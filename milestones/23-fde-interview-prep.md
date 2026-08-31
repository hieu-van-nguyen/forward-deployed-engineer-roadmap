# Milestone 23 — FDE Interview Prep: Live Sessions & Bug Investigations

| Field | Value |
|---|---|
| **Month** | M6 |
| **Weeks** | W23–W24 |
| **Priority** | P1 — Critical |
| **Domain** | FDE Interview Prep |
| **Objective** | Practice 5 Live Scoping/Decomposition sessions & 5 Unfamiliar Codebase Live Bug Investigations |
| **Key Deliverable** | Interview feedback log with documented improvements |

---

## Why This Matters for FDEs

FDE interviews are different from SWE interviews. There's no LeetCode. Instead, you're evaluated on how you think, communicate, and debug in real time — with an evaluator watching. The 5+5 practice sessions in this milestone are specifically designed to replicate the hardest parts of an FDE loop.

---

## FDE Interview Format (What to Expect)

### Round 1: Technical Phone Screen
- 30-45 min
- System design at high level
- "Walk me through how you'd build X for a client"
- Data engineering fundamentals (SQL, CDC, data modeling)

### Round 2: Technical Deep Dive (60-90 min)
- Live coding: build a RAG or agent prototype in 45 minutes
- Architecture design: whiteboard or shared doc
- "How would you handle if the client's DB is on-prem?"

### Round 3: Scoping & Client Simulation
- You play the FDE, interviewer plays a vague exec
- Convert a fuzzy problem to a technical RFC in 30 min
- Follow-up: push back on unrealistic requirements

### Round 4: Unfamiliar Codebase Bug Investigation
- Given a GitHub repo you've never seen
- Find and fix (or explain) a bug in 30 minutes
- Evaluates: exploration methodology, communication, debugging

### Round 5: Presentation / Final Interview
- Present your portfolio project
- Executive-style: assume non-technical audience
- Q&A from a panel

---

## Part 1: 5 Live Scoping Sessions

### Session Structure (30 min each)
1. Partner plays an exec; you play the FDE
2. Partner gives a vague business request (from the list below)
3. You conduct a discovery interview (15 min)
4. You produce a 1-page scope document (10 min)
5. Feedback and review (5 min)

### 5 Practice Scenarios

**Scenario 1:** "We have 100TB of customer data in S3 and we want AI to help us understand our customers better."

**Scenario 2:** "Our legal team reviews 200 contracts a month. Can AI help?"

**Scenario 3:** "We want a chatbot for our internal Confluence knowledge base. It's not working well right now."

**Scenario 4:** "Our data analysts spend 2 days every week running reports. Can we automate this?"

**Scenario 5:** "We just acquired a company with a completely different tech stack. We need them integrated in 3 months."

### Scoping Session Scorecard (use for feedback)

| Skill | 1-Poor | 2-OK | 3-Good | 4-Excellent |
|-------|--------|------|--------|-------------|
| Asked clarifying questions before proposing solutions | | | | |
| Quantified the business problem | | | | |
| Identified data sources and constraints | | | | |
| Proposed realistic scope (not over-promised) | | | | |
| Handled ambiguity without freezing | | | | |
| Identified blockers proactively | | | | |
| Communication was clear and concise | | | | |

---

## Part 2: 5 Unfamiliar Codebase Bug Investigations

### Session Structure (30 min each)
1. Partner provides a GitHub repo URL (from the list below or one they choose)
2. Timer starts — you have 30 minutes
3. Find the bug, explain root cause, propose/implement fix
4. Narrate your thinking OUT LOUD throughout (this is evaluated)

### Bug Investigation Methodology (ATAD)

```
1. ANCHOR — What is the expected behavior? What is the actual behavior?
             "The endpoint should return 200 but returns 500."

2. TRACE — Follow the data flow backward from the failure
            "500 → check server logs → Exception in db.query() → connection pool exhausted"

3. ADAPT — Form a hypothesis and test it
            "Hypothesis: connections aren't being closed after each request. 
             Let me look at the DB connection code..."

4. DELIVER — State the root cause and fix clearly
             "Root cause: db connection not returned to pool in error path.
              Fix: add try/finally block. Here's the diff."
```

### 5 Bug Investigation Repos

1. **Python FastAPI + SQLAlchemy bug** (N+1 query problem)
   - Symptom: endpoint is slow under load
   - Bug: ORM lazy loading in a loop

2. **Kafka consumer bug** (consumer group offset not committing)
   - Symptom: messages processed twice on restart
   - Bug: `enable.auto.commit=false` but manual commit never called

3. **Docker networking bug** (service can't reach another service)
   - Symptom: `ConnectionRefused` between two containers
   - Bug: services in different Docker networks, no network alias

4. **RAG pipeline bug** (retrieval returning wrong chunks)
   - Symptom: answers are irrelevant to questions
   - Bug: embedding model produces different dimensions than index expects

5. **Terraform bug** (resources not updating on `apply`)
   - Symptom: `terraform apply` says "No changes" but resource is wrong
   - Bug: Terraform state is out of sync with actual AWS state

### Bug Investigation Scorecard

| Skill | Score 1-4 |
|-------|-----------|
| Stated expected vs. actual behavior clearly | |
| Asked for / checked logs before guessing | |
| Narrated thinking throughout (not silent) | |
| Formed testable hypotheses | |
| Found root cause (not just symptom) | |
| Proposed correct fix | |
| Completed within time limit | |

---

## Interview Feedback Log Template

```markdown
# Interview Practice Log

## Session: [Date] — [Type: Scoping / Bug Investigation]
**Partner:** [Name]
**Scenario/Repo:** [Description]
**Time taken:** [X min]

### What went well
- [Specific thing]
- [Another thing]

### What to improve
- [Specific thing with concrete next action]
- [Another thing]

### Score (from scorecard)
| Skill | Score |
|-------|-------|
| ... | 3/4 |

### Patterns across sessions
- Recurring strength: [e.g., "Always ask about data ownership early"]
- Recurring weakness: [e.g., "I propose solutions before fully understanding the problem"]
- Action: [What I'll do differently in next session]
```

---

## Common FDE Interview Questions to Prepare

### Technical
1. "Walk me through how you'd build a RAG system for a client with no cloud access."
2. "How would you handle a CDC pipeline where the source schema changes without warning?"
3. "A client's LLM is giving wrong answers 15% of the time. Walk me through your debugging approach."
4. "Design a system to sync 50M records from Oracle to BigQuery in near-real-time."
5. "How would you convince a security team to allow LLM API calls in a production environment?"

### Behavioral
1. "Tell me about a time you had to deliver a project under an unrealistic deadline."
2. "Describe a time you had to push back on a client's technical request."
3. "Tell me about a complex technical concept you explained to a non-technical exec."
4. "Describe a system you built that failed. What would you do differently?"
5. "How do you handle being the expert in the room when you're actually uncertain?"

### Portfolio
1. "Walk me through your RAG project as if I'm a VP of Engineering at a Fortune 500."
2. "What was the hardest engineering problem you solved in your CDC project?"
3. "What eval metrics did you use and why?"
4. "What would you add to your portfolio project if you had 2 more weeks?"

---

## Week-by-Week Practice Schedule

| Day | Activity |
|-----|---------|
| Monday | Scoping Session 1 + feedback log |
| Tuesday | Bug Investigation 1 + feedback log |
| Wednesday | Scoping Session 2 + feedback log |
| Thursday | Bug Investigation 2 + feedback log |
| Friday | Review feedback patterns; update portfolio README |
| Monday | Scoping Session 3 + feedback log |
| Tuesday | Bug Investigation 3 + feedback log |
| Wednesday | Scoping Session 4 + feedback log |
| Thursday | Bug Investigation 4 + feedback log |
| Friday | Mock full interview loop (2 hours) + full debrief |
| Remaining | Scoping 5, Bug 5, final polish |

---

## Checklist

- [ ] 5 scoping sessions completed (logged with feedback)
- [ ] 5 bug investigation sessions completed (logged with feedback)
- [ ] Feedback log identifies top 2 recurring weaknesses with action items
- [ ] ATAD methodology used in all bug investigations (narrate out loud)
- [ ] Portfolio project README updated based on peer feedback
- [ ] 15 behavioral questions answered in writing (STAR format)
- [ ] Mock full interview loop completed
- [ ] Documented improvements from session 1 → session 5

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *Designing Data-Intensive Applications* | Martin Kleppmann | System design fundamentals — databases, streams, and distributed systems that appear in FDE technical screens |
| *Cracking the PM Interview* | Gayle McDowell & Jackie Bavaro | Product sense, estimation, and behavioral questions — adapted for FDE's hybrid technical+consulting interviews |
| *The Mom Test* | Rob Fitzpatrick | Discovery call simulation — prepares you for scoping interview scenarios where you must gather requirements |
| *System Design Interview Vol. 1 & 2* | Alex Xu | End-to-end system design patterns — URL shortener, rate limiter, notification system — adapt for AI use cases |
| *Never Split the Difference* | Chris Voss | Negotiation and communication under pressure — applicable to FDE client objection scenarios in interviews |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Exponent Interview Prep | [tryexponent.com](https://www.tryexponent.com) | Mock PM/technical interviews — adaptable for FDE-style scoping and system design rounds |
| Interviewing.io | [interviewing.io](https://interviewing.io) | Anonymous technical mock interviews with senior engineers — practice system design rounds |
| LeetCode System Design | [leetcode.com/discuss/interview-question/system-design](https://leetcode.com/discuss/interview-question/system-design/) | Community-contributed system design interview Q&A |
| Glassdoor FDE Reviews | [glassdoor.com](https://www.glassdoor.com) | Search "Forward Deployed Engineer" for company-specific interview format and question examples |
| STAR Method Guide | [indeed.com/career-advice/interviewing/how-to-use-the-star-interview-response-technique](https://www.indeed.com/career-advice/interviewing/how-to-use-the-star-interview-response-technique/) | Structure for behavioral answers — crucial for FDE culture-fit and customer impact questions |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Grokking the System Design Interview* | Educative.io | 16 canonical system design problems with walkthrough — adapt each for AI context |
| *Technical Interview Bootcamp* | Udemy / freeCodeCamp | Full interview preparation — data structures, system design, and behavioral questions |
| *AI for Product Managers* | Coursera / Duke | Framing AI feasibility and trade-offs — useful for FDE scoping interview scenarios |
