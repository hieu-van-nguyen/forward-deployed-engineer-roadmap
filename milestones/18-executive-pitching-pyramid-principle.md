# Milestone 18 — Executive Pitching: Pyramid Principle

| Field | Value |
|---|---|
| **Month** | M5 |
| **Weeks** | W17–W18 |
| **Priority** | P2 — High |
| **Domain** | Executive Pitching |
| **Objective** | Master Pyramid Principle communication (Lead with conclusion, group arguments, summarize) |
| **Key Deliverable** | Executive presentation deck for complex tech integration |

---

## Why This Matters for FDEs

Technical brilliance doesn't close deals or get projects approved — clear executive communication does. A VP has 10 minutes and needs to know: does this work, how much does it cost, and what's the risk? FDEs who can't answer in 3 minutes lose the room. The Pyramid Principle is the framework for structured executive communication used by McKinsey, Google, and top consulting firms.

---

## The Pyramid Principle

Developed by Barbara Minto (McKinsey), the principle states:

> **Lead with the answer. Then support it.**

```
         ┌─────────────────────────────┐
         │     GOVERNING THOUGHT       │
         │  (Main recommendation/       │
         │   key takeaway — 1 sentence) │
         └──────────┬──────────────────┘
                    │
          ┌─────────┼─────────┐
          │         │         │
     ┌────▼─┐  ┌────▼─┐  ┌────▼─┐
     │ Key  │  │ Key  │  │ Key  │
     │ Arg  │  │ Arg  │  │ Arg  │
     │  1   │  │  2   │  │  3   │
     └──┬───┘  └──┬───┘  └──┬───┘
        │         │         │
   Supporting  Supporting  Supporting
    evidence    evidence    evidence
```

**Anti-pattern (what SWEs typically do):**
```
"First I analyzed the data, then I found three issues, 
then I looked at solutions A, B, C, and after all that 
analysis, I recommend we go with solution B."
```

**Pyramid Principle (FDE approach):**
```
"I recommend solution B. [pause for reaction]
Here's why: it's 40% cheaper, it deploys in 2 weeks, 
and it integrates with your existing Okta SSO. 
The analysis backing each of those claims is in the appendix."
```

---

## SCQA Framework (Situation-Complication-Question-Answer)

Use this to open any executive presentation:

| Element | What it is | Example |
|---------|-----------|---------|
| **Situation** | Context everyone agrees on | "Your support team handles 10,000 tickets/month" |
| **Complication** | Problem that disrupts the situation | "65% are repetitive FAQ questions, costing 3 FTEs" |
| **Question** | The implicit question this raises | "How do we reduce this cost while maintaining CSAT?" |
| **Answer** | Your recommendation | "Deploy a RAG-powered support chatbot — 60% deflection in 60 days" |

---

## Grouping: MECE (Mutually Exclusive, Collectively Exhaustive)

Your supporting arguments must be MECE:
- **Mutually exclusive:** Arguments don't overlap
- **Collectively exhaustive:** Together, they cover the full answer

**Bad (overlapping):**
1. It's fast to build
2. It saves development time
3. It's scalable

**Good (MECE):**
1. Time-to-value: 6-week deployment vs. 6-month build
2. Cost: $8k/month vs. $400k/year for in-house team
3. Risk: Uses proven components, not novel research

---

## Slide Structure Template

### Slide 1: Executive Summary (SCQA)
```
Headline (your recommendation, 1 line):
  "Implement AI-powered support chatbot to deflect 60% of tickets in 60 days"

─────────────────────────────────────────────────────────────
Situation:    10,000 support tickets/month, 65% repetitive FAQ
Complication: 3 FTEs spent on answerable questions = $450k/yr
Solution:     RAG chatbot trained on your knowledge base
Expected ROI: $350k/yr net savings; payback in 3 months
─────────────────────────────────────────────────────────────
[Appendix: architecture, risks, alternatives considered]
```

### Slide 2: The Problem (Quantified)
- One chart showing the pain: ticket volume, resolution time, cost
- One sentence connecting data to business impact
- No technical jargon

### Slide 3: The Solution
- Simple architecture diagram (boxes and arrows, no code)
- What it does for the USER (not how it works)
- Timeline: key dates only

### Slide 4: Why This Works (Evidence)
- Similar company result / case study
- Pilot data or proof-of-concept results
- Evaluation scores ("87% accuracy on your own FAQ questions")

### Slide 5: What We Need (Call to Action)
- Decision needed: "Approve $X budget for 8-week engagement"
- Data access needed: "Read-only Zendesk API key, Confluence access"
- Stakeholder needed: "1 hour/week from your support team lead"

### Slides 6+: Appendix
Everything technical goes here: system architecture, security review, data model, eval methodology, cost breakdown, alternatives considered.

---

## Common Executive Objections & Responses

| Objection | What they really mean | Response |
|-----------|----------------------|---------|
| "This seems risky" | Show me mitigation | "We've identified 3 risks. Here's how each is handled." |
| "Is this proven?" | Reference customers | "This exact architecture powered [similar company]'s 80% deflection." |
| "How long will this take?" | Fear of endless projects | "8 weeks to MVP, 90 days to production. Here's the timeline." |
| "What if the AI is wrong?" | Liability concern | "Humans review all responses below 85% confidence. No auto-send." |
| "We have a vendor already" | Sunk cost bias | "Our system integrates with [their vendor]. We add the AI layer on top." |
| "The team is too busy" | Priority conflict | "We need 2 hours/week from your team. Here's exactly when." |

---

## Presentation Delivery Tips

1. **Send the deck before the meeting.** Never "walk them through it" slide by slide.
2. **Speak to the headline, not the slide.** Every slide's headline IS your message.
3. **Time box:** Slide 1 (3 min), Slides 2-5 (7 min), Appendix on demand.
4. **Use silence.** After your recommendation, pause. Let them react.
5. **Never apologize for your slides.** "I know there's a lot on this slide" = your fault.
6. **End with a clear ask.** "I need a yes/no on budget approval today."

---

## Deliverable: Executive Presentation

Build a real deck for one of your projects:

**Structure:**
- Slide 1: Executive summary (SCQA + recommendation)
- Slide 2: Quantified problem
- Slide 3: Solution overview (non-technical)
- Slide 4: Evidence / proof points
- Slide 5: Clear ask
- Appendix: Full technical architecture, costs, risks

**Tools:** Google Slides, Pitch.com, or Keynote — keep it clean, max 3 colors.

---

## Checklist

- [ ] Read *The Pyramid Principle* by Barbara Minto (or summary)
- [ ] Practice SCQA opening for 2 different projects (30-second version)
- [ ] Create a 5-slide executive deck for one of your technical projects
- [ ] Deck has a single-sentence recommendation on slide 1
- [ ] Each slide has a headline that IS the message (not a label)
- [ ] Technical details moved to appendix
- [ ] Present the deck to a non-technical peer — can they summarize it back?
- [ ] Prepare answers to 5 objections listed above

---

## 📚 Recommended Books & Online Resources

### Books

| Title | Author | Why It's Relevant |
|-------|--------|------------------|
| *The Pyramid Principle* | Barbara Minto | The definitive book on SCQA and top-down structured communication — read this first |
| *Made to Stick* | Chip Heath & Dan Heath | Why ideas survive or die — SUCCESs framework (Simple, Unexpected, Concrete, Credible, Emotional, Story) |
| *Slide:ology* | Nancy Duarte | Visual storytelling for executive presentations — layout, data visualization, and slide design principles |
| *Resonate* | Nancy Duarte | Presentation structure that creates change — the hero's journey applied to business pitches |
| *The McKinsey Way* | Ethan Rasiel | How top consultants structure problems, solutions, and client presentations — MECE in practice |

### Online Resources

| Resource | URL | Description |
|---------|-----|-------------|
| Pyramid Principle Summary | [thestrategystory.com/pyramid-principle](https://thestrategystory.com/2021/04/21/pyramid-principle-barbara-minto/) | Practical SCQA summary with business examples |
| McKinsey Presentation Templates | [mckinsey.com/featured-insights](https://www.mckinsey.com/featured-insights) | Study McKinsey reports for slide structure — executive summary first, appendix details |
| StoryBrand Framework | [storybrand.com](https://storybrand.com) | Client-as-hero narrative framework — position your solution as the guide, not the hero |
| Duarte Presentation Tips | [duarte.com/resources](https://www.duarte.com/resources/) | Free templates, webinars, and articles on executive communication |
| Sequoia Capital Pitch Deck | [sequoiacap.com/article/writing-a-business-plan](https://www.sequoiacap.com/article/writing-a-business-plan/) | Classic pitch structure — Problem, Solution, Market, Why Us — adapt for AI solution pitches |

### Courses

| Course | Platform | Focus |
|--------|---------|-------|
| *Presentation Skills: Speechwriting, Slides and Delivery* | Coursera / University of Washington | End-to-end communication — structure, visual design, and delivery for business audiences |
| *Executive Communications* | LinkedIn Learning | C-suite communication, brevity, and executive presence in technical presentations |
| *Data Visualization for Storytelling* | DataCamp | Turning data into compelling visual narratives for non-technical stakeholders |
