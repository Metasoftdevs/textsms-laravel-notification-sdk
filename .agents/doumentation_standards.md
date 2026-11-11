# System Comprehension & Explainability Directive

**Classification:** Engineering Standards Companion\
**Applies to:** System architecture, feature implementation, refactoring, integration, debugging, and technical documentation\
**Companion documents:** `IMPLEMENTATION_STANDARDS.md` and `EDGE_CASES.md`

## 1. Core Principle

**Do not merely implement a system. Make its behavior understandable, observable, explainable, and verifiable.**

As AI-assisted development increases the speed and volume of code production, human oversight must shift toward understanding architectural decisions, validating behavior, identifying failure modes, and evaluating system correctness.

Treat human comprehension as a first-class engineering deliverable.

A successful implementation must satisfy three independent objectives:

1. **Implementation correctness:** The system satisfies its functional, non-functional, security, and reliability requirements.
2. **Behavioral completeness:** Important edge cases, state transitions, failure modes, and recovery mechanisms are explicitly addressed.
3. **Comprehension quality:** Engineers can efficiently understand what the system does, why it works, how its components interact, where it can fail, and how to verify its behavior.

Documentation must not become an exhaustive description of code. It must provide an effective mental model of the system.

---

## 2. Progressive Explanation Strategy

Choose the representation that makes the subject easiest to understand, rather than defaulting to lengthy prose.

Use progressively richer explanatory formats when they materially reduce cognitive load.

### Level 1 — Controlled Technical Writing

For architectural decisions, implementation behavior, and edge-case explanations:

- Follow approximately 80% of ASD-STE100 simplified technical English principles.
- Use short, direct sentences and precise terminology.
- Prefer active voice and explicit actors.
- Express one principal idea per sentence.
- Define domain-specific terms and use them consistently.
- Clearly distinguish facts, requirements, assumptions, hypotheses, and recommendations.
- Explain cause-and-effect relationships explicitly.
- Avoid ambiguous references, vague assertions, and unnecessary jargon.
- Preserve technical accuracy; do not simplify away important constraints.

Prefer the explanation:

"When the payment provider times out, the service checks the transaction status before retrying. This prevents duplicate charges."

Over:

"In the event of a potentially transient downstream provider failure, the system may engage in retry-related compensatory behavior."

**Objective:** Minimize interpretation effort without sacrificing technical precision.

### Level 2 — Diagrams and Visual Models

When a concept involves multiple components, complex state, concurrency, or nontrivial execution flow, prefer a diagram alongside concise prose.

Choose the representation according to the problem:

| Problem | Preferred representation |
|---|---|
| Component responsibilities | Architecture/component diagram |
| Cross-service interaction | Sequence diagram |
| Stateful behavior | State-machine diagram |
| Branching decisions | Flowchart/decision tree |
| Data dependencies | Data-flow or dependency graph |
| Asynchronous processing | Event timeline |
| Race conditions | Concurrent execution timeline |
| Edge cases | Decision matrix |
| Database relationships | Entity-relationship diagram |
| Performance behavior | Latency breakdown or performance chart |

Prefer diagrams-as-code formats such as Mermaid, PlantUML, or Graphviz for maintainable documentation.

Every diagram must agree with the actual implementation. Label important boundaries, states, triggers, and failure paths.

### Level 3 — Interactive Executable Explanations

For sufficiently complex workflows, explore whether an interactive HTML explanation or small web application would improve comprehension.

Useful interactive artifacts include:

- Step-by-step execution simulators.
- State-machine explorers.
- Request/response inspectors.
- Edge-case scenario selectors.
- Retry and timeout visualizations.
- Event ordering and concurrency simulators.
- Data transformation visualizers.
- Configuration and decision-rule explorers.

Where possible, let reviewers modify inputs, activate failure conditions, and observe the resulting state transitions.

Interactive explainers should be self-contained, reproducible, and disposable unless explicitly designated as maintained tooling.

**Important:** An interactive model is an educational abstraction, not proof of production correctness. Clearly distinguish simulated behavior from behavior verified through tests or production evidence.

### Level 4 — Animated and Narrated Explanations

For particularly complex systems, evaluate whether an animated explanation or narrated walkthrough would communicate behavior more effectively.

Use these artifacts selectively for:

- Distributed-system interactions.
- Multi-stage execution pipelines.
- Complex concurrency scenarios.
- Architectural trade-offs.
- Failure propagation and recovery.
- Onboarding to unfamiliar systems.

Prefer programmatically reproducible animations when practical.

Do not generate animations or videos merely for presentation value. Their purpose is to reduce the effort required to understand a difficult concept.

Static documentation must remain sufficient to understand critical system behavior without requiring video playback.

---

## 3. Implementation Comprehension Contract

For every nontrivial system or feature implementation, provide the following information.

### A. Intent and Mental Model

Explain:

- What problem does this implementation solve?
- What is the simplest accurate mental model?
- What are its responsibilities and boundaries?
- What assumptions must remain true?
- What does it explicitly not support?

Begin with a concise explanation understandable to an engineer who is unfamiliar with the implementation.

### B. Architecture and Execution

Describe:

- Major components and their responsibilities.
- Dependencies and external integrations.
- The primary execution path.
- Data inputs, transformations, and outputs.
- Persistent state and ownership.
- Asynchronous operations and ordering constraints.
- Important architectural decisions and trade-offs.

Provide relevant architecture or sequence diagrams when interaction complexity warrants them.

### C. Behavioral and State Model

Make implicit behavior explicit.

Identify:

- Supported states and state transitions.
- Transition triggers and preconditions.
- Relevant invariants.
- Side effects.
- Idempotency expectations.
- Transaction boundaries.
- Concurrency assumptions.
- Ordering and consistency guarantees.

Specify which conditions must always hold, which are eventually guaranteed, and which are best-effort.

### D. Edge Cases and Failure Modes

Connect the implementation directly to `EDGE_CASES.md`.

For each important scenario, document:

1. Triggering condition.
2. Expected behavior.
3. Actual implementation mechanism.
4. State changes and side effects.
5. Error handling or recovery behavior.
6. How to reproduce or verify the scenario.
7. Remaining risks or limitations.

Explicitly examine boundary values, malformed inputs, partial failures, timeouts, retries, concurrent requests, duplicate events, stale data, unavailable dependencies, and recovery after interruption.

Do not infer correctness solely from the happy path.

### E. Verification and Evidence

Distinguish what was implemented from what was verified.

Explain:

- Which requirements have corresponding tests.
- Which edge cases are covered.
- Which invariants are checked.
- Which failure scenarios were simulated.
- Which properties remain unverified.
- What runtime observations would detect incorrect behavior.

Where relevant, connect statements to test names, source files, metrics, logs, traces, or reproducible examples.

Never claim a behavior is validated solely because it appears logically correct.

### F. Operational Understanding

Explain how another engineer can:

- Determine whether the feature is healthy.
- Diagnose an unexpected result.
- Identify failed or incomplete operations.
- Reproduce important failures.
- Recover from common incidents.
- Safely disable or roll back the feature.

Identify relevant observability signals, alert conditions, operational limits, and recovery procedures.

---

## 4. Adaptive Artifact Selection

Do not produce every explanatory format for every implementation.

Apply a proportionality rule:

**Choose the smallest set of artifacts that allows a reviewer to understand and verify the important behavior with minimal cognitive effort.**

| Complexity | Minimum expectation |
|---|---|
| Simple, localized change | Concise explanation and relevant tests |
| Moderate feature | Mental model, flow description, edge-case matrix |
| Stateful or integrated feature | Architecture/sequence diagram, state model, failure analysis |
| Complex distributed system | Multiple focused diagrams, concurrency analysis, recovery model, verification evidence |
| Exceptionally complex behavior | Consider interactive simulations or animated walkthroughs |

Escalate the explanation format when prose becomes difficult to follow or important behavior cannot be understood without mentally executing the code.

Prefer one accurate, focused diagram over several decorative diagrams.

---

## 5. Critical Reasoning and Self-Review

Before considering the comprehension deliverable complete, critically review it from the perspective of an engineer unfamiliar with the system.

Ask:

- Can the primary execution path be understood without reading every source file?
- Are the most important design decisions explained?
- Can each state transition be identified?
- Are hidden assumptions visible?
- Are component boundaries and ownership clear?
- Are concurrency and failure scenarios understandable?
- Can a reviewer reproduce important edge cases?
- Are explanations consistent with the implementation and tests?
- Have we separated observed behavior from expected behavior?
- Are meaningful trade-offs and limitations disclosed?
- Would a diagram, simulation, or interactive example eliminate significant ambiguity?

If not, improve the explanation or choose a more effective representation.

Do not compensate for unclear thinking with additional documentation volume.

---

## 6. Documentation Integrity and Maintainability

Explanatory artifacts must remain connected to implementation reality.

- Prefer documentation generated from or validated against code where practical.
- Keep implementation standards as the authority for how code should be written.
- Keep edge-case documentation as the authority for scenario coverage.
- Use comprehension artifacts to explain relationships, behavior, and reasoning across those documents.
- Link to existing documentation instead of duplicating large sections.
- Update impacted diagrams and explanations when implementation behavior changes.
- Mark hypothetical scenarios, simulations, and conceptual abstractions explicitly.
- Prefer reproducible, version-controlled artifacts over opaque generated outputs.
- Avoid maintaining complex interactive artifacts unless their long-term value justifies the cost.

The implementation and its tests remain the primary technical evidence. Explanatory artifacts are additional interfaces for understanding that evidence.

---

## 7. Required Delivery Summary

For nontrivial implementations, conclude with a structured comprehension summary:

**System intent:** What was implemented and why.

**Mental model:** A concise explanation of how it works.

**Execution flow:** The primary execution path, with an appropriate diagram when useful.

**Critical invariants:** Properties that must remain true.

**Edge-case behavior:** Important exceptional scenarios and their outcomes.

**Failure and recovery:** How the system responds to partial or complete failure.

**Verification evidence:** Tests, observations, and checks supporting correctness claims.

**Trade-offs and limitations:** Known compromises, assumptions, and unresolved risks.

**Comprehension artifacts:** Links to diagrams, interactive explainers, or other supporting materials, if produced.

**Reviewer focus:** The most important decisions, behaviors, or risks requiring human attention.

Do not generate empty sections simply to satisfy the template. Use concise references where existing documents already contain the required evidence.

---

## 8. Governing Directive

**Optimize for human understanding, not documentation volume.**

As implementation becomes increasingly automated, engineering quality depends on the ability to inspect, reason about, and verify increasingly complex systems.

Code explains *what executes*.

Implementation standards explain *how it should be engineered*.

Edge-case documentation explains *where behavior may diverge or fail*.

Comprehension artifacts explain *why the system behaves as it does, how its parts interact, and how a human can establish confidence in its correctness*.

The ultimate goal is not merely software that works.

It is software whose behavior can be understood, challenged, verified, and maintained by humans who did not write it.
