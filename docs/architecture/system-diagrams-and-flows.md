# System Diagrams and Data Flow Architecture

## System Context Diagram (C4 Level 1)

```
                    ┌─────────────────────────────────────────┐
                    │                                         │
                    │              User                       │
                    │         (Chat Interface)                │
                    │                                         │
                    └─────────────────┬───────────────────────┘
                                      │ Conversation Input
                                      ▼
    ┌─────────────────────────────────────────────────────────────────┐
    │                                                                 │
    │                PHP Agent Framework                              │
    │            (RunAgent.php Chat Mode)                             │
    │                                                                 │
    │  ┌─────────────────────────────────────────────────────────┐    │
    │  │                                                         │    │
    │  │         Hybrid Follow-up Recognition System             │    │
    │  │                                                         │    │
    │  │    ┌─────────────┐  ┌──────────────┐  ┌─────────────┐   │    │
    │  │    │   Pattern   │  │   Context    │  │     LLM     │   │    │
    │  │    │   Matcher   │  │   Analyzer   │  │ Classifier  │   │    │
    │  │    │   (Fast)    │  │  (Medium)    │  │   (Slow)    │   │    │
    │  │    └─────────────┘  └──────────────┘  └─────────────┘   │    │
    │  │                                                         │    │
    │  └─────────────────────────────────────────────────────────┘    │
    │                                                                 │
    └─────────────────────────────────────────────────────────────────┘
                                      │ Enhanced Task
                                      ▼
                    ┌─────────────────────────────────────────┐
                    │                                         │
                    │           Agent Execution               │
                    │         (Tools & Actions)               │
                    │                                         │
                    └─────────────────────────────────────────┘
```

## Container Diagram (C4 Level 2)

```
┌───────────────────────────────────────────────────────────────────────────┐
│                          PHP Agent Framework                                │
├───────────────────────────────────────────────────────────────────────────┤
│                                                                           │
│  ┌─────────────────┐    ┌──────────────────────────────────────────────┐   │
│  │                 │    │                                              │   │
│  │   RunAgent      │    │      Hybrid Follow-up Recognition           │   │
│  │   Command       │◄───┤              System                          │   │
│  │                 │    │                                              │   │
│  │ • Chat Mode     │    │  ┌─────────────────────────────────────────┐ │   │
│  │ • Context Mgmt  │    │  │        FollowUpRecognizer              │ │   │
│  │ • User I/O      │    │  │       (Main Orchestrator)              │ │   │
│  └─────────────────┘    │  │                                         │ │   │
│                         │  │ ┌─────────────┐  ┌─────────────────────┐│ │   │
│                         │  │ │   Pattern   │  │   ConversationContext││ │   │
│                         │  │ │   Matcher   │  │     Manager         ││ │   │
│                         │  │ │             │  │                     ││ │   │
│                         │  │ │ • Regex     │  │ • Entity Tracking   ││ │   │
│                         │  │ │ • Rules     │  │ • Action History    ││ │   │
│                         │  │ │ • Fast      │  │ • Context Window    ││ │   │
│                         │  │ └─────────────┘  └─────────────────────┘│ │   │
│                         │  │                                         │ │   │
│                         │  │ ┌─────────────┐  ┌─────────────────────┐│ │   │
│                         │  │ │   Context   │  │    LLM Classifier   ││ │   │
│                         │  │ │   Analyzer  │  │                     ││ │   │
│                         │  │ │             │  │ • Optimized Prompt  ││ │   │
│                         │  │ │ • Topic     │  │ • Reduced Context   ││ │   │
│                         │  │ │   Similarity│  │ • Caching           ││ │   │
│                         │  │ │ • Entity    │  │ • Fallback Only     ││ │   │
│                         │  │ │   Detection │  │                     ││ │   │
│                         │  │ └─────────────┘  └─────────────────────┘│ │   │
│                         │  └─────────────────────────────────────────┘ │   │
│                         │                                              │   │
│                         └──────────────────────────────────────────────┘   │
│                                                                           │
│  ┌─────────────────┐    ┌──────────────────────────────────────────────┐   │
│  │                 │    │                                              │   │
│  │    Agent        │◄───┤           Performance & Metrics             │   │
│  │   Execution     │    │                                              │   │
│  │                 │    │ • RecognitionMetrics                         │   │
│  │ • Tools         │    │ • Processing Time Tracking                   │   │
│  │ • Actions       │    │ • Method Usage Statistics                    │   │
│  │ • Context       │    │ • Cache Hit Rates                           │   │
│  └─────────────────┘    │ • Performance Alerts                        │   │
│                         └──────────────────────────────────────────────┘   │
│                                                                           │
└───────────────────────────────────────────────────────────────────────────┘
```

## Component Diagram (C4 Level 3) - Follow-up Recognition System

```
┌─────────────────────────────────────────────────────────────────────────┐
│                  Hybrid Follow-up Recognition System                   │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │                   FollowUpRecognizer                            │    │
│  │                  (Main Orchestrator)                           │    │
│  │                                                                 │    │
│  │  analyze(input, previousResponse): RecognitionResult           │    │
│  │  ├─ Phase 1: Pattern Matching (Fast Path)                     │    │
│  │  ├─ Phase 2: Context Analysis (Medium Path)                   │    │
│  │  └─ Phase 3: LLM Classification (Slow Path)                   │    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                   │                                      │
│                                   ▼                                      │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────┐   │
│  │                 │  │                 │  │                         │   │
│  │  PatternMatcher │  │ ContextAnalyzer │  │    LLMClassifier        │   │
│  │                 │  │                 │  │                         │   │
│  │  match()        │  │  analyze()      │  │  classify()             │   │
│  │  ├─ High Conf   │  │  ├─ Topic       │  │  ├─ Optimized Prompt    │   │
│  │  ├─ Med Conf    │  │  │   Similarity │  │  ├─ Reduced Context     │   │
│  │  ├─ Low Conf    │  │  ├─ Entity      │  │  └─ JSON Response       │   │
│  │  └─ No Match    │  │  │   References │  │                         │   │
│  │                 │  │  └─ Action      │  │                         │   │
│  │                 │  │     Continuation│  │                         │   │
│  └─────────────────┘  └─────────────────┘  └─────────────────────────┘   │
│           │                       │                         │           │
│           ▼                       ▼                         ▼           │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────────┐   │
│  │                 │  │                 │  │                         │   │
│  │  PatternRules   │  │ConversationContext│  │     App\Agent\LLM      │   │
│  │                 │  │                 │  │                         │   │
│  │  ├─ High Conf   │  │  ├─ Exchanges   │  │  json()                 │   │
│  │  │   Patterns   │  │  ├─ Entities    │  │                         │   │
│  │  ├─ Med Conf    │  │  ├─ Actions     │  │                         │   │
│  │  │   Patterns   │  │  └─ Context     │  │                         │   │
│  │  └─ Low Conf    │  │     Window      │  │                         │   │
│  │     Patterns    │  │                 │  │                         │   │
│  └─────────────────┘  └─────────────────┘  └─────────────────────────┘   │
│                                                                         │
│  ┌─────────────────────────────────────────────────────────────────┐    │
│  │                    Result Value Objects                        │    │
│  │                                                                 │    │
│  │  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐│    │
│  │  │Recognition  │ │ Pattern     │ │ Context     │ │ LLM         ││    │
│  │  │Result       │ │ Result      │ │ Result      │ │ Result      ││    │
│  │  │             │ │             │ │             │ │             ││    │
│  │  │├─ Enhanced  │ │├─ Match     │ │├─ Follow-up │ │├─ Follow-up ││    │
│  │  ││  Input     │ ││  Type      │ ││  Flag      │ ││  Flag      ││    │
│  │  │├─ Method    │ │├─ Confidence│ │├─ Context   │ │├─ Enhanced  ││    │
│  │  │├─ Time      │ │└─ Enhanced  │ ││  Elements  │ ││  Input     ││    │
│  │  │└─ Confidence│ │   Input     │ │└─ Confidence│ │└─ Confidence││    │
│  │  └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘│    │
│  └─────────────────────────────────────────────────────────────────┘    │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

## Data Flow Diagram

```
┌─────────────┐
│    User     │
│   Input     │
│ "fix that"  │
└──────┬──────┘
       │ 1. Raw Input
       ▼
┌─────────────────────┐
│  FollowUpRecognizer │
│    Orchestrator     │
└──────┬──────────────┘
       │ 2. Analyze Request
       ▼
┌─────────────────────┐
│   PatternMatcher    │      ┌─────────────────┐
│                     │◄────►│  PatternRules   │
│ • Regex matching    │      │ • High/Med/Low  │
│ • Rule evaluation   │      │   Confidence    │
│ • Fast processing   │      │ • Pattern Types │
└──────┬──────────────┘      └─────────────────┘
       │ 3a. Match Found (70-80%)
       ▼
┌─────────────────────┐
│   PatternResult     │
│ • Enhanced: "fix    │
│   that (referring   │
│   to previous)"     │
│ • Confidence: 0.95  │
└──────┬──────────────┘
       │
       ▼ ✅ Return Enhanced Input

       │ 3b. No Match (20-30%)
       ▼
┌─────────────────────┐      ┌─────────────────────┐
│  ContextAnalyzer    │◄────►│ ConversationContext │
│                     │      │                     │
│ • Topic similarity  │      │ • Recent exchanges  │
│ • Entity references │      │ • Tracked entities  │
│ • Action continuity │      │ • Last actions      │
└──────┬──────────────┘      └─────────────────────┘
       │ 4a. Context Match (15-20%)
       ▼
┌─────────────────────┐
│   ContextResult     │
│ • Enhanced: "fix    │
│   that (referring   │
│   to: UserService)" │
│ • Confidence: 0.80  │
└──────┬──────────────┘
       │
       ▼ ✅ Return Enhanced Input

       │ 4b. No Context Match (5-10%)
       ▼
┌─────────────────────┐      ┌─────────────────────┐
│   LLMClassifier     │◄────►│    App\Agent\LLM    │
│                     │      │                     │
│ • Optimized prompt  │      │ • JSON response     │
│ • Reduced context   │      │ • OpenAI API        │
│ • Cached results    │      │ • Error handling    │
└──────┬──────────────┘      └─────────────────────┘
       │ 5. LLM Classification
       ▼
┌─────────────────────┐
│     LLMResult       │
│ • is_follow_up: T/F │
│ • enhanced_input    │
│ • Confidence: 0.8+  │
└──────┬──────────────┘
       │
       ▼ ✅ Return Enhanced Input

┌─────────────────────┐
│ RecognitionResult   │
│                     │
│ • Original Input    │
│ • Enhanced Input    │
│ • Processing Method │
│ • Time Taken        │
│ • Confidence Score  │
└──────┬──────────────┘
       │ 6. Return to RunAgent
       ▼
┌─────────────────────┐
│    RunAgent.php     │
│  enhanceTaskWith-   │
│    Context()        │
│                     │
│ Returns enhanced    │
│ input to agent      │
└─────────────────────┘
```

## Sequence Diagram - Pattern Matching Path (Fast)

```
User            RunAgent        FollowUpRecognizer    PatternMatcher    PatternRules
 │                 │                    │                   │               │
 │──"fix it"──────►│                    │                   │               │
 │                 │                    │                   │               │
 │                 │──analyze()────────►│                   │               │
 │                 │                    │                   │               │
 │                 │                    │──match()─────────►│               │
 │                 │                    │                   │               │
 │                 │                    │                   │──getHigh──────►│
 │                 │                    │                   │  ConfPatterns │
 │                 │                    │                   │               │
 │                 │                    │                   │◄──patterns────│
 │                 │                    │                   │               │
 │                 │                    │                   │ regex match   │
 │                 │                    │                   │ "fix (it|that)"
 │                 │                    │                   │               │
 │                 │                    │◄──PatternResult───│               │
 │                 │                    │   (match=true,    │               │
 │                 │                    │    confidence=0.95)│               │
 │                 │                    │                   │               │
 │                 │◄──RecognitionResult──│                  │               │
 │                 │   (enhanced="fix it │                  │               │
 │                 │    (referring to    │                  │               │
 │                 │     previous)",     │                  │               │
 │                 │    method="pattern",│                  │               │
 │                 │    time=1ms)        │                  │               │
 │                 │                    │                   │               │
 │◄──enhanced─────│                    │                   │               │
 │   input        │                    │                   │               │
```

## Sequence Diagram - LLM Fallback Path (Slow)

```
User            RunAgent        FollowUpRecognizer    LLMClassifier    App\Agent\LLM
 │                 │                    │                   │               │
 │──"elaborate"───►│                    │                   │               │
 │                 │                    │                   │               │
 │                 │──analyze()────────►│                   │               │
 │                 │                    │                   │               │
 │                 │                    │ Pattern: No Match │               │
 │                 │                    │ Context: No Match │               │
 │                 │                    │                   │               │
 │                 │                    │──classify()──────►│               │
 │                 │                    │                   │               │
 │                 │                    │                   │──json()──────►│
 │                 │                    │                   │  (optimized   │
 │                 │                    │                   │   prompt)     │
 │                 │                    │                   │               │
 │                 │                    │                   │◄──response────│
 │                 │                    │                   │  {"is_follow_ │
 │                 │                    │                   │   up": true,  │
 │                 │                    │                   │   "enhanced_" │
 │                 │                    │                   │   input": ... }│
 │                 │                    │                   │               │
 │                 │                    │◄──LLMResult───────│               │
 │                 │                    │                   │               │
 │                 │◄──RecognitionResult──│                  │               │
 │                 │   (method="llm",   │                   │               │
 │                 │    time=1000ms)    │                   │               │
 │                 │                    │                   │               │
 │◄──enhanced─────│                    │                   │               │
 │   input        │                    │                   │               │
```

## State Diagram - Context Management

```
                    ┌─────────────────────┐
                    │                     │
                    │    Initial State    │
                    │   (Empty Context)   │
                    │                     │
                    └──────────┬──────────┘
                               │ First Exchange
                               ▼
                    ┌─────────────────────┐
                    │                     │
                    │   Building Context  │
                    │  (1-3 exchanges)    │
                    │                     │
                    │ • Extracting entities│
                    │ • Tracking actions  │
                    │ • Learning patterns │
                    └──────────┬──────────┘
                               │ More Exchanges
                               ▼
                    ┌─────────────────────┐
                    │                     │
                    │   Rich Context      │
                    │  (4-10 exchanges)   │
                    │                     │
                    │ • Full entity map   │
                    │ • Action history    │
                    │ • Topic continuity  │
                    └──────────┬──────────┘
                               │ Context Limit Reached
                               ▼
         ┌─────────────────────────────────────┐
         │                                     │
         │          Context Compression        │
         │                                     │
         │ • Summarize older exchanges         │
         │ • Preserve key entities             │
         │ • Maintain action continuity        │
         │ • Compress to fit window            │
         └─────────────────┬───────────────────┘
                           │ Compressed
                           ▼
                    ┌─────────────────────┐
                    │                     │
                    │ Optimized Context   │
                    │  (Steady State)     │
                    │                     │
                    │ • Compressed history│
                    │ • Active entities   │
                    │ • Current topics    │
                    └─────────────────────┘
```

## Performance Flow Diagram

```
Input Received
      │
      ▼
┌─────────────┐     ┌─────────────────────────────────────────┐
│   Start     │────►│              Metrics                    │
│   Timer     │     │                                         │
└─────────────┘     │ • Start time recorded                   │
                    │ • Method tracking initialized           │
                    └─────────────────────────────────────────┘
      │
      ▼
┌─────────────┐     Performance Path Analysis:
│   Pattern   │     ┌─────────────────────────────────────────┐
│   Matching  │────►│ 70-80% of cases                         │
│   (~1ms)    │     │ Target: <1ms processing                 │
└─────────────┘     │ Success: Return enhanced input          │
      │ No Match    └─────────────────────────────────────────┘
      ▼
┌─────────────┐     ┌─────────────────────────────────────────┐
│   Context   │────►│ 15-20% of cases                         │
│  Analysis   │     │ Target: <50ms processing                │
│   (~50ms)   │     │ Success: Return enhanced input          │
└─────────────┘     └─────────────────────────────────────────┘
      │ No Match
      ▼
┌─────────────┐     ┌─────────────────────────────────────────┐
│     LLM     │────►│ 5-10% of cases                          │
│ Classification     │ Target: <1000ms processing              │
│  (~1000ms)  │     │ Success: Return enhanced input          │
└─────────────┘     └─────────────────────────────────────────┘
      │
      ▼
┌─────────────┐     ┌─────────────────────────────────────────┐
│    End      │────►│              Metrics                    │
│   Timer     │     │                                         │
└─────────────┘     │ • End time recorded                     │
                    │ • Method success tracked                │
                    │ • Performance logged                    │
                    │ • Cache updated                         │
                    └─────────────────────────────────────────┘

Overall Performance Targets:
• Pattern Path: 1ms × 75% = 0.75ms average
• Context Path: 50ms × 20% = 10ms average
• LLM Path: 1000ms × 5% = 50ms average
• Combined Average: ~61ms (vs current ~1000ms)
• 94% performance improvement
```

## Integration Flow with Existing RunAgent.php

```
    ┌─────────────────────────────────────────────────────────┐
    │                   RunAgent.php                          │
    │                  Chat Mode Flow                         │
    └─────────────────────────────────────────────────────────┘
                              │
                              ▼
    ┌─────────────────────────────────────────────────────────┐
    │  runChatMode(Agent $agent, string $initialTask)         │
    │                                                         │
    │  1. Initialize ConversationContext                      │
    │  2. Run conversation loop                               │
    │  3. For each user input:                                │
    │     ├─ Get finalResponse = agent->run(task)             │
    │     ├─ Update context->addExchange(task, finalResponse) │
    │     ├─ Get nextInput from user                          │
    │     └─ task = enhanceTaskWithContext(nextInput, resp)   │
    └─────────────────────────────────────────────────────────┘
                              │
                              ▼
    ┌─────────────────────────────────────────────────────────┐
    │  enhanceTaskWithContext(string $input, string $prev)    │
    │                                                         │
    │  OLD: Direct LLM call every time                        │
    │  NEW: Use FollowUpRecognizer with hybrid approach       │
    │                                                         │
    │  $recognizer = new FollowUpRecognizer($this->context);  │
    │  $result = $recognizer->analyze($input, $prev);         │
    │  return $result->getEnhancedInput();                    │
    └─────────────────────────────────────────────────────────┘
                              │
                              ▼
    ┌─────────────────────────────────────────────────────────┐
    │           Hybrid Recognition Process                     │
    │                                                         │
    │  ┌─────────────┐  ┌─────────────┐  ┌─────────────────┐  │
    │  │   Pattern   │  │   Context   │  │       LLM       │  │
    │  │   Matcher   │→ │   Analyzer  │→ │   Classifier    │  │
    │  │    (Fast)   │  │  (Medium)   │  │     (Slow)      │  │
    │  └─────────────┘  └─────────────┘  └─────────────────┘  │
    │         70%              20%               10%          │
    └─────────────────────────────────────────────────────────┘
                              │
                              ▼
    ┌─────────────────────────────────────────────────────────┐
    │                Enhanced Input                           │
    │                                                         │
    │  • Original: "fix it"                                   │
    │  • Enhanced: "fix it (referring to the previous code)"  │
    │  • Method: pattern | context | llm                      │
    │  • Time: 1ms | 50ms | 1000ms                           │
    │  • Confidence: 0.70-0.95                               │
    └─────────────────────────────────────────────────────────┘
                              │
                              ▼
    ┌─────────────────────────────────────────────────────────┐
    │              Back to Agent Execution                    │
    │                                                         │
    │  Enhanced input is passed to Agent->run() for          │
    │  normal tool execution and response generation          │
    └─────────────────────────────────────────────────────────┘
```

This comprehensive system design provides clear architectural guidance for implementing the hybrid follow-up recognition system, with detailed data flows, performance characteristics, and integration patterns with the existing PHP Agent framework.
