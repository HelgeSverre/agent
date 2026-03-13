# Agent Framework - Architecture Diagrams

## System Overview

```
  ┌──────────────────────┐       ┌──────────────────────────┐
  │  CLI                 │       │  WebUI                   │
  │  php agent run "task"│       │  php agent web --port=8080│
  └──────────┬───────────┘       └────────────┬─────────────┘
             │                                │
             v                                v
  ┌──────────────────┐            ┌───────────────────────┐
  │  RunAgent Command │            │  WebUIServer Command  │
  └────────┬─────────┘            └──────────┬────────────┘
           │                                 │
           │                                 v
           │                      ┌────────────────────┐
           │                      │  WebSocketHandler   │
           │                      └─────────┬──────────┘
           │                                │
           │                                v
           │                      ┌────────────────────┐
           │                      │  MessageHandler     │
           │                      └─────────┬──────────┘
           │                                │
           └──────────────┬─────────────────┘
                          v
                 ┌────────────────┐
                 │     Agent      │
                 └───┬──┬──┬──┬──┘
                     │  │  │  │
        ┌────────────┘  │  │  └──────────────┐
        v               │  │                 v
  ┌───────────┐         │  │        ┌─────────────────┐
  │  Prompt   │         │  │        │ ParallelExecutor │
  │  Builder  │         │  │        └─────────────────┘
  └─────┬─────┘         │  │
        │               │  │        ┌─────────────────┐
        v               │  └───────>│    Planner       │
  ┌───────────┐         │           └─────────────────┘
  │  LLM API  │         v
  └───────────┘   ┌───────────┐     ┌─────────────────┐
       ^          │  Context  │     │ SessionManager   │
       :          │  Manager  │     └────────┬────────┘
       :          └─────┬─────┘              │
       :                │                    v
       :                v           ┌─────────────────────────┐
       :          ┌────────────┐    │ storage/agent-sessions/ │
       :..........│ Compressor │    │ *.json                  │
                  └────────────┘    └─────────────────────────┘

  ┌─────────────────────────────────────────────────┐
  │  Tool Registry                                  │
  │                                                 │
  │  ReadFile  WriteFile  RunCommand                │
  │  SearchWeb  BrowseWebsite  Speak                │
  └─────────────────────────────────────────────────┘
```

---

## Agent Main Loop

```
  ┌─────────────────────┐
  │  Agent::run(task)    │
  └──────────┬──────────┘
             v
  ┌─────────────────────────┐
  │ Trim intermediate steps │<─────────────────────────────────────┐
  │ to max ~15              │                                      │
  └──────────┬──────────────┘                                      │
             v                                                     │
  ┌──────────────────────────┐                                     │
  │ Increment iteration      │                                     │
  └──────────┬───────────────┘                                     │
             v                                                     │
      ┌──────────────┐                                             │
      │ Iteration >  │── Yes ──> [Return: Max iterations reached]  │
      │ maxIter?     │                                             │
      └──────┬───────┘                                             │
             │ No                                                  │
             v                                                     │
  ┌──────────────────────────────┐                                 │
  │ Prompt::decideNextStep()     │                                 │
  │ Build prompt with task,      │                                 │
  │ tools, plan, context history │                                 │
  └──────────┬───────────────────┘                                 │
             v                                                     │
  ┌──────────────────────────────┐                                 │
  │ LLM::functionCall()          │                                 │
  │ Select tool or final_answer  │                                 │
  └──────────┬───────────────────┘                                 │
             v                                                     │
      ┌──────────────────┐                                         │
      │ Function call    │── No ──┐                                │
      │ returned?        │        v                                │
      └──────┬───────────┘  ┌───────────┐                         │
             │ Yes          │ Thought   │── Yes ──> [Return thought]
             v              │ exists?   │                          │
  ┌────────────────────┐    └─────┬─────┘                         │
  │ Trigger action hook│          │ No                             │
  └──────────┬─────────┘          └────────────────────────────────┤
             v                                                     │
  ┌────────────────────┐                                           │
  │ Record action step │                                           │
  └──────────┬─────────┘                                           │
             v                                                     │
      ┌──────────────┐                                             │
      │ Tool =       │── Yes ──> Save session ──> [Return answer]  │
      │ final_answer?│                                             │
      └──────┬───────┘                                             │
             │ No                                                  │
             v                                                     │
      ┌────────────────┐                                           │
      │ Recently       │── Yes ──> Record skip ───────────────────>│
      │ executed? 30s  │                                           │
      └──────┬─────────┘                                           │
             │ No                                                  │
             v                                                     │
      ┌────────────────┐                                           │
      │ Queue for      │── Yes ──┐                                 │
      │ parallel?      │         v                                 │
      └──────┬─────────┘   ┌──────────┐                           │
             │ No          │ Queue    │                            │
             │             │ >= 2?    │── No ─────────────────────>│
             │             └────┬─────┘                            │
             │                  │ Yes                              │
             │                  v                                  │
             │        ┌────────────────────┐                       │
             │        │ executeParallel()   │                      │
             │        │ Record results      │─────────────────────>│
             │        └────────────────────┘                       │
             v                                                     │
      ┌────────────────┐                                           │
      │ Circuit breaker│── No ──> Record blocked ────────────────>│
      │ allows?        │                                           │
      └──────┬─────────┘                                           │
             │ Yes                                                 │
             v                                                     │
      ┌────────────────┐                                           │
      │ Consecutive    │── Yes ──> Record failure ────────────────>│
      │ failures >= 3? │                                           │
      └──────┬─────────┘                                           │
             │ No                                                  │
             v                                                     │
  ┌────────────────────┐                                           │
  │ tool->execute(args)│                                           │
  └──────────┬─────────┘                                           │
             v                                                     │
      ┌──────────┐                                                 │
      │ Success? │── Yes ──> Record result ───────────────────────>│
      └────┬─────┘                                                 │
           │ No                                                    │
           └──> Record error, increment failures ─────────────────>┘
```

---

## Tool Execution

```
  ┌───────────────────────┐
  │ Tool::execute(args)   │
  └──────────┬────────────┘
             v
  ┌──────────────────────────┐
  │ Validate 'run' method    │
  └──────────┬───────────────┘
             v
  ┌──────────────────────────┐
  │ Get argument definitions │
  │ from arguments()         │
  └──────────┬───────────────┘
             v
  ┌──────────────────────────┐<──────────┐
  │ For each defined argument│           │
  └──────────┬───────────────┘           │
             v                           │
      ┌─────────────────┐               │
      │ Required &      │── Yes ──> [Return error: missing param]
      │ missing?        │               │
      └──────┬──────────┘               │
             │ No                        │
             v                           │
  ┌──────────────────────┐               │
  │ Convert types        │               │
  │ (DateTime, Carbon)   │               │
  └──────────┬───────────┘               │
             v                           │
      ┌─────────────┐                   │
      │ More args?  │── Yes ────────────┘
      └──────┬──────┘
             │ No
             v
  ┌───────────────────────┐
  │ Filter to valid args  │
  │ Arr::only()           │
  └──────────┬────────────┘
             v
  ┌───────────────────────────────┐
  │ call_user_func_array(         │
  │   [$this, 'run'], $validArgs) │
  └──────────┬────────────────────┘
             v
      ┌────────────┐
      │ Exception? │── Yes ──> [Return "Error: message"]
      └──────┬─────┘
             │ No
             v
      [Return tool output]
```

---

## Planning Flow

```
  ┌─────────────────────────┐
  │ Planner::createPlan()   │
  └──────────┬──────────────┘
             v
  ┌──────────────────────────────┐
  │ Build planning prompt        │
  │ with task + available tools  │
  └──────────┬───────────────────┘
             v
  ┌──────────────────────────────┐
  │ LLM::json(prompt)            │
  │ Generate structured plan     │
  └──────────┬───────────────────┘
             v
      ┌───────────────────┐
      │ Has 'steps' in    │── No ──> Return default plan
      │ response?         │          (single-step fallback)
      └──────┬────────────┘
             │ Yes
             v
      [Return plan]


  Plan Structure:
  ┌───────────────────────────────────────┐
  │ summary: string                       │
  │ estimated_tools: int                  │
  │ complexity: simple | moderate | complex│
  │                                       │
  │ steps[]:                              │
  │ ┌───────────────────────────────────┐ │
  │ │ step_number: int                  │ │
  │ │ description: string              │ │
  │ │ tools: string[]                  │ │
  │ │ can_parallelize: bool            │ │
  │ │ depends_on: int[]               │ │
  │ └───────────────────────────────────┘ │
  └───────────────────────────────────────┘
```

---

## Prompt Construction

```
  Prompt::decideNextStep() assembles:

  ┌──────────────────────────────────────────────────┐
  │  ## Agent Task Framework                         │
  ├──────────────────────────────────────────────────┤
  │  ## GOAL (if set)                                │
  ├──────────────────────────────────────────────────┤
  │  ## TASK                                         │
  │  Current task description                        │
  ├──────────────────────────────────────────────────┤
  │  ## EXECUTION PLAN (if exists)                   │
  │  Step details with dependencies                  │
  ├──────────────────────────────────────────────────┤
  │  ## AVAILABLE TOOLS                              │
  │  Name, description, parameters for each tool     │
  ├──────────────────────────────────────────────────┤
  │  ## IMPORTANT GUIDELINES                         │
  │  Tool usage instructions                         │
  ├──────────────────────────────────────────────────┤
  │  ## CONVERSATION HISTORY                         │
  │  Previous actions, observations, thoughts        │
  ├──────────────────────────────────────────────────┤
  │  ## YOUR NEXT STEPS                              │
  │  Numbered instructions                           │
  └──────────────────────────────────────────────────┘
```

---

## Context Management

```
  ┌───────────────────────────────────┐
  │ ContextManager::manageContext()   │
  └──────────┬────────────────────────┘
             v
      ┌────────────────────┐
      │ Compression        │── No ──> [Return steps unchanged]
      │ enabled?           │
      └──────┬─────────────┘
             │ Yes
             v
      ┌──────────────────┐
      │ shouldCompress()? │── No ──> [Return steps unchanged]
      └──────┬───────────┘
             │ Yes
             v
  ┌──────────────────────────────────┐
  │ performIntelligentCompression()  │
  └──────────┬───────────────────────┘
             v
      ┌──────────────────┐
      │ Still over       │── No ──> [Return compressed steps]
      │ step limit?      │
      └──────┬───────────┘
             │ Yes
             v
  ┌──────────────────────────────────────────────────┐
  │ intelligentTrim()                                │
  │                                                  │
  │  1. Score each step by importance:               │
  │     ┌──────────────────────────────────────────┐ │
  │     │ Persistent ops (file_write, run_cmd) 100 │ │
  │     │ Final answer                          75 │ │
  │     │ Recent steps                          50 │ │
  │     │ Observations                          30 │ │
  │     │ Thoughts                              25 │ │
  │     └──────────────────────────────────────────┘ │
  │  2. Sort by score descending                     │
  │  3. Keep top N steps up to maxSteps              │
  └──────────┬───────────────────────────────────────┘
             v
      [Return trimmed steps]
```

### Context Compression Strategy

```
  ┌─────────────────────────────────┐
  │ analyzeSteps()                  │
  │ Classify priority, categorize   │
  └──────────┬──────────────────────┘
             v
      ┌──────────────┐
      │ Step count?  │
      └──┬───┬───┬───┘
         │   │   │
         │   │   └── else ──────────────────┐
         │   │                              v
         │   │                   ┌────────────────────────┐
         │   │                   │ LLM-enhanced           │
         │   │                   │ compression            │
         │   │                   │ Full structured summary │
         │   │                   └──────────┬─────────────┘
         │   │                              v
         │   │                   ┌────────────────────────┐
         │   │                   │ Output:                │
         │   │                   │  executive_summary     │
         │   │                   │  critical_facts[]      │
         │   │                   │  file_operations[]     │
         │   │                   │  user_preferences[]    │
         │   │                   │  key_decisions[]       │
         │   │                   │  errors_encountered[]  │
         │   │                   │  current_state         │
         │   │                   │  next_steps[]          │
         │   │                   └────────────────────────┘
         │   │
         │   └── > 70% critical ──> Intelligent compression
         │                          Preserve critical, summarize rest
         │
         └── < 5 steps ──────────> Simple compression
                                   Join actions + results
```

---

## Session Management

```
  SAVE FLOW
  ─────────

  Agent runs a step
         │
         v
  ┌────────────────┐
  │ recordStep()   │
  └──────┬─────────┘
         v
   ┌─────────────┐
   │ sessionId   │── No ──> [No persistence]
   │ set?        │
   └──────┬──────┘
          │ Yes
          v
  ┌──────────────────────────┐
  │ Create AgentState        │
  │  task, steps, iteration, │
  │  goal, status, plan      │
  └──────────┬───────────────┘
             v
  ┌────────────────────────────────────┐
  │ SessionManager::save()             │
  │ storage/agent-sessions/{id}.json   │
  └────────────────────────────────────┘


  RESUME FLOW
  ────────────

  ┌───────────────────────────┐
  │ Agent::fromSession(id)    │
  └──────────┬────────────────┘
             v
  ┌───────────────────────────┐
  │ SessionManager::load(id)  │
  └──────────┬────────────────┘
             v
  ┌───────────────────────────┐
  │ AgentState::fromArray()   │
  └──────────┬────────────────┘
             v
  ┌───────────────────────────┐
  │ Restore agent properties  │
  │ steps, iteration, plan    │
  └──────────┬────────────────┘
             v
  ┌───────────────────────────┐
  │ enableSession(id)         │
  └──────────┬────────────────┘
             v
  [Agent ready for next task]


  AGENT STATE
  ───────────
  ┌────────────────────────────────────┐
  │ task:             string           │
  │ intermediateSteps: array           │
  │ currentIteration: int              │
  │ goal:             ?string          │
  │ status:           running|completed│
  │ executionPlan:    ?array           │
  │ createdAt:        ISO8601          │
  └────────────────────────────────────┘
```

---

## Parallel Execution

```
  ┌──────────────────────────────────┐
  │ executeParallel(toolCalls)       │
  └──────────┬───────────────────────┘
             v
  ┌────────────────────────────┐<──────────────────────────────┐
  │ Queue not empty OR         │                               │
  │ processes still running?   │── No ──> [Return results]     │
  └──────────┬─────────────────┘                               │
             │ Yes                                             │
             v                                                 │
  ┌────────────────────────────────┐                           │
  │ startProcesses()               │                           │
  │ Launch up to maxProcesses=4    │                           │
  │                                │                           │
  │ For each queued tool:          │                           │
  │  - base64 encode args          │                           │
  │  - Build CLI command:          │                           │
  │    php agent agent:execute-tool│                           │
  │    --tool=name --args=b64      │                           │
  │  - process->start()            │                           │
  └──────────┬─────────────────────┘                           │
             v                                                 │
  ┌────────────────────────────────┐                           │
  │ checkProcesses()               │                           │
  └──────────┬─────────────────────┘                           │
             v                                                 │
      ┌──────────────────┐                                     │
      │ Process still    │── No ──> Collect result ───────────>│
      │ running?         │          Parse JSON output          │
      └──────┬───────────┘                                     │
             │ Yes                                             │
             v                                                 │
      ┌──────────────────┐                                     │
      │ Exceeded 30s     │── Yes ──> Force stop ──> Collect ──>│
      │ timeout?         │                                     │
      └──────┬───────────┘                                     │
             │ No                                              │
             v                                                 │
      usleep(10ms) ────────────────────────────────────────────┘


  DEDUPLICATION
  ─────────────
  ┌──────────────────────────────────────────────┐
  │ getToolExecutionKey() = toolName:mainArg     │
  │                   │                          │
  │                   v                          │
  │ Track in recentlyExecutedTools + timestamp   │
  │                   │                          │
  │                   v                          │
  │ Block re-execution within 30 seconds         │
  └──────────────────────────────────────────────┘
```

---

## Chat Mode

```
  ┌─────────────────────────┐
  │ runChatMode()           │
  └──────────┬──────────────┘
             v
  ┌────────────────────────────────┐
  │ Initialize FollowUpRecognizer  │
  └──────────┬─────────────────────┘
             v
  ┌────────────────────┐<──────────────────────────────┐
  │ agent->run(task)   │                               │
  └──────────┬─────────┘                               │
             v                                         │
  ┌────────────────────────┐                           │
  │ Display response       │                           │
  └──────────┬─────────────┘                           │
             v                                         │
  ┌──────────────────────────────────┐                 │
  │ followUpRecognizer               │                 │
  │   .updateContext(task, response)  │                 │
  └──────────┬───────────────────────┘                 │
             v                                         │
  ┌────────────────────────┐                           │
  │ Prompt user for input  │                           │
  └──────────┬─────────────┘                           │
             v                                         │
      ┌─────────────┐                                  │
      │ Input =     │── Yes ──> Show metrics ──> [End] │
      │ "exit"?     │          (pattern/context/        │
      └──────┬──────┘           LLM rates)             │
             │ No                                      │
             v                                         │
  ┌──────────────────────────────┐                     │
  │ enhanceTaskWithContext()     │                     │
  └──────────┬───────────────────┘                     │
             v                                         │
  ┌──────────────────────────────┐                     │
  │ agent->resetForNextTask()   │                     │
  │ Preserve history, clear state│                     │
  └──────────────────────────────┘─────────────────────┘
```

### Follow-Up Recognition (3-Layer System)

```
  User input
       │
       v
  ┌─────────────────────────────────────────────────────────────┐
  │ LAYER 1: Pattern Matching (~1ms)                            │
  │                                                             │
  │  Pronoun ref (it/that/this)    ──> confidence 0.9           │
  │  Continuation (another/more)   ──> confidence 0.85          │
  │  Command (yes/ok/do it)        ──> confidence 0.95          │
  │  Negation (no/skip/cancel)     ──> confidence 0.9           │
  │  Clarification (what/how/why)  ──> confidence 0.85          │
  └──────────┬──────────────────────────────────────────────────┘
             v
      ┌─────────────────┐
      │ Confidence > 0.8│── Yes ──> Enhance with pattern context
      └──────┬──────────┘           (extract subject/action/entity)
             │ No                        │
             v                           │
  ┌─────────────────────────────────┐    │
  │ LAYER 2: Context Analysis (~50ms│    │
  │                                 │    │
  │  Topic similarity     x 0.4    │    │
  │  Entity references    x 0.3    │    │
  │  Action continuation  x 0.3    │    │
  │  Threshold: > 0.4              │    │
  └──────────┬──────────────────────┘    │
             v                           │
      ┌─────────────────┐               │
      │ Confidence > 0.7│── Yes ──> Enhance with tracked context
      └──────┬──────────┘           (topic + entity overlap)
             │ No                        │
             v                           │
  ┌──────────────────────────────┐       │
  │ LAYER 3: LLM Fallback       │       │
  │ (~1000ms)                    │       │
  │ LLM determines relationship │       │
  └──────────┬───────────────────┘       │
             v                           │
      Enhance with LLM result            │
             │                           │
             └───────────┬───────────────┘
                         v
                  [Enhanced task string]
```

---

## WebUI Architecture

```
  ┌──────────────────┐
  │  Browser Client  │
  └────┬─────────┬───┘
       │         │
       │ HTTP    │ WebSocket
       v         v
  ┌────────┐  ┌──────────────────────┐
  │ Serve  │  │ WebSocket Connection │
  │ static │  └──┬─────────┬─────┬──┘
  │ HTML   │     │         │     │
  └────────┘     v         │     v
          ┌──────────┐     │  ┌──────────────┐
          │ onOpen   │     │  │ onClose      │
          │ Create   │     │  │ Destroy      │
          │ session  │     │  │ session      │
          └──────────┘     │  └──────────────┘
                           v
                    ┌─────────────┐
                    │ onMessage   │
                    │ Route type  │
                    └──────┬──────┘
                           │
          ┌────────┬───────┼───────┬──────────┐
          v        v       v       v          v
     execute   ping    get      cancel    get
     _task     ──>     _status  _task     _context
       │       pong      │       │          │
       v                 v       v          v
  ┌──────────┐    status   task      context
  │ Active   │    response cancelled response
  │ task?    │
  └──┬───┬───┘
     │   │
  Yes│   │No
     v   v
  [err] Create taskId
        Send task_started
            │
            v
     ┌──────────────────┐
     │ Setup agent hooks│
     │ for streaming    │
     └──────┬───────────┘
            v
     ┌──────────────────┐
     │ agent->run(task) │
     └──────┬───────────┘
            │
            │  Stream via WebSocket:
            │
            │  ┌─────────────────────────────┐
            │  │ action     ──> tool + args   │
            │  │ thought    ──> reasoning     │
            │  │ observation──> tool result   │
            │  │ tool_exec  ──> start         │
            │  │ tool_success / tool_error    │
            │  │ parallel_* ──> parallel info │
            │  │ context_compressed           │
            │  │ final_answer ──> completion  │
            │  └─────────────────────────────┘
            │
            v
     Send task_completed
```

---

## CLI Entry Points

```
  php agent
       │
       ├──> run "task"
       │         │
       │         ├── --plan ──────> Create plan via Planner
       │         │                  Show to user, ask confirmation
       │         │                         │
       │         ├── --resume id ──> Load session ──┐
       │         │                  fromSession()    │
       │         │                                   │
       │         ├── --chat ──────> Enter chat       │
       │         │                  mode loop ──┐    │
       │         │                              │    │
       │         ├── --parallel ──> Enable       │    │
       │         │                  ParallelExec │    │
       │         │                       │       │    │
       │         ├── --speak ──> TTS     │       │    │
       │         │                │       │       │    │
       │         └── --web ──> Redirect  │       │    │
       │                    to web cmd   │       │    │
       │                                 v       v    v
       │                         ┌──────────────────────┐
       │                         │ Create Agent          │
       │                         │ with tools + hooks    │
       │                         └──────────┬───────────┘
       │                                    v
       │                         ┌──────────────────────┐
       │                         │ agent->run(task)      │
       │                         └──────────┬───────────┘
       │                                    v
       │                         ┌──────────────────────┐
       │                         │ Display result        │
       │                         └──────────────────────┘
       │
       │    Registered Hooks:
       │    ┌───────────────────────────────────┐
       │    │ start          ──> display task    │
       │    │ action         ──> display tool    │
       │    │ thought        ──> display reason  │
       │    │ observation    ──> display result  │
       │    │ final_answer   ──> display done    │
       │    │ max_iteration  ──> display error   │
       │    └───────────────────────────────────┘
       │
       ├──> web --port=8080
       │         │
       │         v
       │    Create WebSocketHandler
       │    Create IoServer on port
       │    Open browser (optional)
       │    server->run() [blocking]
       │
       └──> tool:execute <tool> <args>
                 │
                 v
            Find tool by name
            Decode args
            Execute directly
```

---

## Circuit Breaker Pattern

```
                    Execution succeeds
                    Reset failure count
                 ┌──────────────────────┐
                 │                      │
                 v                      │
            ┌─────────┐                 │
  start ──> │ CLOSED  │────────────────┘
            │         │
            └────┬────┘
                 │
                 │ 3 consecutive failures
                 │ on same tool+args
                 v
            ┌─────────┐
            │  OPEN   │──┐
            │         │  │ Execution blocked
            └─────────┘  │ Return helpful error
                 ^       │
                 └───────┘

  CLOSED: Normal operation. Each failure increments counter.
          Success resets counter to zero.

  OPEN:   Tool+args combination blocked. Agent receives
          context about prior failures to try an
          alternative approach.
```

---

## Data Flow: Task Lifecycle

```
  User          CLI/WebUI        Agent          Prompt         LLM           Tool        Session      Context
   │                │              │              │              │             │            │            │
   │  Submit task   │              │              │              │             │            │            │
   │───────────────>│              │              │              │             │            │            │
   │                │  run(task)   │              │              │             │            │            │
   │                │─────────────>│              │              │             │            │            │
   │                │              │              │              │             │            │            │
   │                │              │  ┌───── LOOP: until complete or max iterations ────────────────┐  │
   │                │              │  │           │              │             │            │        │  │
   │                │              │  │  manageContext(steps)    │             │            │        │  │
   │                │              │──┼──────────────────────────┼─────────────┼────────────┼───────>│  │
   │                │              │<─┼──────────────────────────┼─────────────┼────────────┼────────│  │
   │                │              │  │           │              │             │            │        │  │
   │                │              │  │  decideNextStep()        │             │            │        │  │
   │                │              │──┼──────────>│              │             │            │        │  │
   │                │              │<─┼───────────│              │             │            │        │  │
   │                │              │  │           │              │             │            │        │  │
   │                │              │  │  functionCall(prompt, schema)          │            │        │  │
   │                │              │──┼───────────┼─────────────>│             │            │        │  │
   │                │              │<─┼───────────┼──────────────│             │            │        │  │
   │                │              │  │           │              │             │            │        │  │
   │                │              │  │  ┌─── if final_answer ─────────────────────────────────┐    │  │
   │                │              │  │  │        │              │             │            │   │    │  │
   │                │              │  │  │  saveState(completed) │             │            │   │    │  │
   │                │              │──┼──┼────────┼──────────────┼─────────────┼───────────>│   │    │  │
   │                │  Return ans  │  │  │        │              │             │            │   │    │  │
   │                │<─────────────│  │  │        │              │             │            │   │    │  │
   │  Display result│              │  │  │        │              │             │            │   │    │  │
   │<───────────────│              │  │  └────────┼──────────────┼─────────────┼────────────┼───┘    │  │
   │                │              │  │           │              │             │            │        │  │
   │                │              │  │  ┌─── if tool call ────────────────────────────────────┐    │  │
   │                │              │  │  │        │              │             │            │   │    │  │
   │                │              │  │  │  execute(args)        │             │            │   │    │  │
   │                │              │──┼──┼────────┼──────────────┼────────────>│            │   │    │  │
   │                │              │<─┼──┼────────┼──────────────┼─────────────│            │   │    │  │
   │                │              │  │  │        │              │             │            │   │    │  │
   │                │              │  │  │  saveState(running)   │             │            │   │    │  │
   │                │              │──┼──┼────────┼──────────────┼─────────────┼───────────>│   │    │  │
   │                │              │  │  └────────┼──────────────┼─────────────┼────────────┼───┘    │  │
   │                │              │  │           │              │             │            │        │  │
   │                │              │  └─────────────────────────────────────────────────────────────┘  │
   │                │              │              │              │             │            │            │
```
