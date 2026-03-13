# Agent Framework - Architecture Diagrams

## System Overview

```mermaid
graph TB
    CLI["CLI<br/>php agent run 'task'"]
    WebUI["WebUI<br/>php agent web --port=8080"]

    CLI --> RunAgent["RunAgent Command"]
    WebUI --> WebUIServer["WebUIServer Command"]

    RunAgent --> Agent
    WebUIServer --> WSHandler["WebSocketHandler"]
    WSHandler --> MsgHandler["MessageHandler"]
    MsgHandler --> Agent

    Agent --> Prompt["Prompt Builder"]
    Agent --> Tools["Tool Registry"]
    Agent --> SessionMgr["SessionManager"]
    Agent --> CtxMgr["ContextManager"]
    Agent --> ParallelExec["ParallelExecutor"]
    Agent --> Planner

    Prompt --> LLM["LLM API"]
    Tools --> ReadFile & WriteFile & RunCommand & SearchWeb & BrowseWebsite & Speak

    CtxMgr --> Compressor["ContextCompressor"]
    Compressor -.-> LLM

    SessionMgr --> Storage["storage/agent-sessions/*.json"]
```

---

## Agent Main Loop

```mermaid
flowchart TD
    Start([Agent::run task]) --> TrimSteps["Trim intermediate steps<br/>to max ~15"]
    TrimSteps --> IncIter["Increment iteration counter"]
    IncIter --> MaxCheck{Iteration ><br/>maxIterations?}
    MaxCheck -- Yes --> MaxReached([Return: Max iterations reached])
    MaxCheck -- No --> Decide["Prompt::decideNextStep()<br/>Build prompt with task, tools,<br/>plan, context history"]
    Decide --> LLMCall["LLM::functionCall()<br/>Select tool or final_answer"]
    LLMCall --> HasFnCall{Function call<br/>returned?}

    HasFnCall -- No --> TextResponse{Thought<br/>in response?}
    TextResponse -- Yes --> ReturnThought([Return thought as answer])
    TextResponse -- No --> Loop

    HasFnCall -- Yes --> TriggerHook["Trigger 'action' hook"]
    TriggerHook --> RecordAction["Record action step"]
    RecordAction --> IsFinal{Tool =<br/>final_answer?}

    IsFinal -- Yes --> SaveState["Save session state"]
    SaveState --> Complete([Return answer])

    IsFinal -- No --> RecentCheck{Was recently<br/>executed?<br/>within 30s}
    RecentCheck -- Yes --> SkipObs["Record skip observation"]
    SkipObs --> Loop

    RecentCheck -- No --> ParallelCheck{Should queue<br/>for parallel?}
    ParallelCheck -- Yes --> Queue["Add to parallel queue"]
    Queue --> QueueSize{Queue >= 2?}
    QueueSize -- No --> Loop
    QueueSize -- Yes --> ExecParallel["executeParallelTools()"]
    ExecParallel --> RecordResults["Record parallel results"]
    RecordResults --> Loop

    ParallelCheck -- No --> ExecTool["executeTool()"]
    ExecTool --> CBCheck{Circuit breaker<br/>allows?}
    CBCheck -- No --> BlockedObs["Record blocked observation"]
    BlockedObs --> Loop

    CBCheck -- Yes --> FailCheck{Consecutive<br/>failures >= 3?}
    FailCheck -- Yes --> FailObs["Record failure observation"]
    FailObs --> Loop

    FailCheck -- No --> RunTool["tool->execute(args)"]
    RunTool --> ToolResult{Success?}
    ToolResult -- Yes --> SuccessObs["Record result observation"]
    ToolResult -- No --> ErrorObs["Record error observation<br/>Increment failure counter"]

    SuccessObs --> Loop
    ErrorObs --> Loop

    Loop["Save session state"] --> TrimSteps
```

---

## Tool Execution

```mermaid
flowchart TD
    Start([Tool::execute args]) --> Validate["Validate 'run' method exists"]
    Validate --> GetArgs["Get argument definitions<br/>from arguments()"]
    GetArgs --> Loop["For each defined argument"]
    Loop --> Required{Required &<br/>missing?}
    Required -- Yes --> Error([Return error: missing param])
    Required -- No --> Convert["Convert types<br/>DateTime, Carbon, etc."]
    Convert --> NextArg{More args?}
    NextArg -- Yes --> Loop
    NextArg -- No --> Filter["Filter to valid args only<br/>Arr::only()"]
    Filter --> Call["call_user_func_array<br/>this, 'run', validArgs"]
    Call --> Exception{Exception?}
    Exception -- Yes --> CatchError(["Return 'Error: message'"])
    Exception -- No --> Result([Return tool output])
```

---

## Planning Flow

```mermaid
flowchart TD
    Start([Planner::createPlan]) --> BuildPrompt["Build planning prompt<br/>with task + available tools"]
    BuildPrompt --> LLMJson["LLM::json(prompt)<br/>Generate structured plan"]
    LLMJson --> ValidPlan{Has 'steps'<br/>in response?}
    ValidPlan -- No --> Default["Return default plan<br/>single-step fallback"]
    ValidPlan -- Yes --> Return([Return plan])

    subgraph Plan Structure
        direction TB
        P1["summary: string"]
        P2["steps: array"]
        P3["estimated_tools: int"]
        P4["complexity: simple | moderate | complex"]

        subgraph Step
            S1["step_number: int"]
            S2["description: string"]
            S3["tools: string array"]
            S4["can_parallelize: bool"]
            S5["depends_on: int array"]
        end
    end
```

---

## Prompt Construction

```mermaid
flowchart LR
    subgraph Prompt::decideNextStep
        direction TB
        A["## Agent Task Framework"]
        B["## GOAL<br/>(if set)"]
        C["## TASK<br/>Current task description"]
        D["## EXECUTION PLAN<br/>(if exists, with step details)"]
        E["## AVAILABLE TOOLS<br/>Name, description, parameters"]
        F["## IMPORTANT GUIDELINES<br/>Tool usage instructions"]
        G["## CONVERSATION HISTORY<br/>Previous actions, observations,<br/>thoughts, exchanges"]
        H["## YOUR NEXT STEPS<br/>Numbered instructions"]

        A --> B --> C --> D --> E --> F --> G --> H
    end
```

---

## Context Management

```mermaid
flowchart TD
    Start([ContextManager::manageContext]) --> Enabled{Compression<br/>enabled?}
    Enabled -- No --> Return([Return steps unchanged])
    Enabled -- Yes --> ShouldCompress{shouldCompress()?}
    ShouldCompress -- No --> Return
    ShouldCompress -- Yes --> Compress["performIntelligentCompression()"]
    Compress --> StillOver{Still over<br/>step limit?}
    StillOver -- No --> Return2([Return compressed steps])
    StillOver -- Yes --> T1

    subgraph Trim["intelligentTrim()"]
        direction TB
        T1["Score each step by importance"]
        T2["Persistent ops: 100 pts<br/>Final answer: 75 pts<br/>Recent steps: 50 pts<br/>Observations: 30 pts<br/>Thoughts: 25 pts"]
        T3["Sort by score descending"]
        T4["Keep top N steps<br/>up to maxSteps limit"]
        T1 --> T2 --> T3 --> T4
    end

    T4 --> Return3([Return trimmed steps])
```

### Context Compression Strategy

```mermaid
flowchart TD
    Analyze["analyzeSteps()<br/>Classify priority, categorize"] --> Select{Step count?}
    Select -- "< 5 steps" --> Simple["Simple compression<br/>Join actions + results"]
    Select -- "> 70% critical" --> Intelligent["Intelligent compression<br/>Preserve critical, summarize rest"]
    Select -- else --> LLMEnhanced["LLM-enhanced compression<br/>Full structured summary"]

    LLMEnhanced --> Output["JSON output"]

    subgraph LLM Compression Output
        direction TB
        O1["executive_summary"]
        O2["critical_facts[]"]
        O3["file_operations[]"]
        O4["user_preferences[]"]
        O5["key_decisions[]"]
        O6["errors_encountered[]"]
        O7["current_state"]
        O8["next_steps[]"]
    end
```

---

## Session Management

```mermaid
flowchart TD
    subgraph Save Flow
        RunStep["Agent runs a step"] --> RecordStep["recordStep()"]
        RecordStep --> HasSession{sessionId<br/>set?}
        HasSession -- No --> Skip([No persistence])
        HasSession -- Yes --> CreateState["Create AgentState<br/>task, steps, iteration,<br/>goal, status, plan"]
        CreateState --> SaveJson["SessionManager::save()<br/>storage/agent-sessions/id.json"]
    end

    subgraph Resume Flow
        Resume["Agent::fromSession(id)"] --> Load["SessionManager::load(id)"]
        Load --> Hydrate["AgentState::fromArray()"]
        Hydrate --> Restore["Restore agent properties<br/>steps, iteration, plan"]
        Restore --> Enable["enableSession(id)"]
        Enable --> Ready([Agent ready for next task])
    end

    subgraph AgentState
        direction LR
        AS1["task: string"]
        AS2["intermediateSteps: array"]
        AS3["currentIteration: int"]
        AS4["goal: ?string"]
        AS5["status: running | completed"]
        AS6["executionPlan: ?array"]
        AS7["createdAt: ISO8601"]
    end
```

---

## Parallel Execution

```mermaid
flowchart TD
    Start([executeParallel toolCalls]) --> Loop{Queue not empty<br/>OR processes running?}
    Loop -- No --> Results([Return results array])
    Loop -- Yes --> StartProc["startProcesses()<br/>Launch up to maxProcesses=4"]

    StartProc --> CreateProc["For each queued tool:<br/>base64 encode args<br/>Build CLI command:<br/>php agent agent:execute-tool<br/>--tool=name --args=b64"]
    CreateProc --> Launch["process->start()"]
    Launch --> CheckProc["checkProcesses()"]

    CheckProc --> Running{Process<br/>still running?}
    Running -- Yes --> Timeout{Exceeded<br/>30s timeout?}
    Timeout -- Yes --> ForceStop["Force stop process"]
    Timeout -- No --> Sleep["usleep(10ms)"]
    Running -- No --> Collect["Collect result<br/>Parse JSON output"]

    ForceStop --> Collect
    Sleep --> Loop
    Collect --> Loop

    subgraph Deduplication
        direction TB
        D1["getToolExecutionKey()<br/>toolName:mainArg"]
        D2["Track in recentlyExecutedTools<br/>with timestamp"]
        D3["Block re-execution<br/>within 30 seconds"]
        D1 --> D2 --> D3
    end
```

---

## Chat Mode

```mermaid
flowchart TD
    Start([runChatMode]) --> Init["Initialize FollowUpRecognizer"]
    Init --> RunTask["agent->run(task)"]
    RunTask --> ShowResponse["Display response"]
    ShowResponse --> UpdateCtx["followUpRecognizer<br/>.updateContext(task, response)"]
    UpdateCtx --> AskInput["Prompt user for input"]
    AskInput --> IsExit{Input = exit?}
    IsExit -- Yes --> Metrics["Show metrics<br/>pattern/context/LLM rates"]
    Metrics --> End([End chat])
    IsExit -- No --> Enhance["enhanceTaskWithContext()"]
    Enhance --> Reset["agent->resetForNextTask()<br/>Preserve history, clear state"]
    Reset --> RunTask
```

### Follow-Up Recognition (3-Layer System)

```mermaid
flowchart TD
    Input["User input"] --> L1["Layer 1: Pattern Matching<br/>~1ms"]
    L1 --> L1Check{Confidence > 0.8?}
    L1Check -- Yes --> Enhance1["Enhance with pattern context<br/>Extract subject/action/entity"]
    L1Check -- No --> L2["Layer 2: Context Analysis<br/>~50ms"]

    L2 --> L2Check{Confidence > 0.7?}
    L2Check -- Yes --> Enhance2["Enhance with tracked context<br/>Topic + entity overlap"]
    L2Check -- No --> L3["Layer 3: LLM Fallback<br/>~1000ms"]
    L3 --> Enhance3["LLM determines relationship"]

    subgraph "Layer 1 Patterns"
        direction TB
        P1["Pronoun: it/that/this → 0.9"]
        P2["Continuation: another/more → 0.85"]
        P3["Command: yes/ok/do it → 0.95"]
        P4["Negation: no/skip → 0.9"]
        P5["Clarification: what/how → 0.85"]
    end

    subgraph "Layer 2 Scoring"
        direction TB
        S1["Topic similarity × 0.4"]
        S2["Entity references × 0.3"]
        S3["Action continuation × 0.3"]
        S4["Threshold: > 0.4"]
    end

    Enhance1 --> Output(["Enhanced task string"])
    Enhance2 --> Output
    Enhance3 --> Output
```

---

## WebUI Architecture

```mermaid
flowchart TD
    Browser["Browser Client"] -->|HTTP| HTTPServe["Serve static HTML"]
    Browser -->|WebSocket| WSConn["WebSocket Connection"]

    WSConn --> OnOpen["onOpen: Create session<br/>webui_{random}"]
    WSConn --> OnMsg["onMessage: Route by type"]
    WSConn --> OnClose["onClose: Destroy session"]

    OnMsg --> MsgType{Message type?}

    MsgType -->|execute_task| ExecTask["handleExecuteTask()"]
    MsgType -->|ping| Pong["Send pong"]
    MsgType -->|get_status| Status["Send status_response"]
    MsgType -->|cancel_task| Cancel["Send task_cancelled"]
    MsgType -->|get_context| Context["Send context_response"]

    ExecTask --> ActiveCheck{Task already<br/>active?}
    ActiveCheck -- Yes --> Reject["Send error: task in progress"]
    ActiveCheck -- No --> CreateTask["Create taskId<br/>Send task_started"]
    CreateTask --> SetupHooks["Setup agent hooks<br/>for streaming"]
    SetupHooks --> RunAgent["agent->run(task)"]

    RunAgent --> Stream["Stream events via WebSocket"]

    subgraph "Streamed Activity Events"
        direction TB
        E1["action → tool name + args"]
        E2["thought → reasoning"]
        E3["observation → tool result"]
        E4["tool_execution → start"]
        E5["tool_success / tool_error"]
        E6["parallel_* → parallel status"]
        E7["context_compressed"]
        E8["final_answer → completion"]
    end

    Stream --> TaskDone["Send task_completed"]
```

---

## CLI Entry Points

```mermaid
flowchart TD
    CLI["php agent"] --> RunCmd["run 'task'"]
    CLI --> WebCmd["web --port=8080"]
    CLI --> ToolCmd["tool:execute tool args"]

    RunCmd --> Flags{Flags?}

    Flags -->|--plan| Plan["Create plan via Planner<br/>Show to user<br/>Ask confirmation"]
    Flags -->|--resume id| Resume["Load session<br/>Agent::fromSession()"]
    Flags -->|--chat| Chat["Enter chat mode loop"]
    Flags -->|--parallel| Parallel["Enable ParallelExecutor"]
    Flags -->|--speak| TTS["Enable text-to-speech"]
    Flags -->|--web| WebRedirect["Redirect to web command"]

    Plan --> CreateAgent
    Resume --> RunTask
    Parallel --> CreateAgent

    CreateAgent["Create Agent<br/>with tools + hooks"] --> RunTask["agent->run(task)"]
    Chat --> CreateAgent

    RunTask --> Output["Display result"]

    subgraph "Registered Hooks"
        direction TB
        H1["start → display task"]
        H2["action → display tool icon"]
        H3["thought → display reasoning"]
        H4["observation → display result"]
        H5["final_answer → display completion"]
        H6["max_iteration → display error"]
    end

    WebCmd --> StartServer["Create WebSocketHandler<br/>Create IoServer on port<br/>Open browser (optional)"]
    StartServer --> Listen["server->run() - blocking"]

    ToolCmd --> DirectExec["Find tool by name<br/>Decode args<br/>Execute directly"]
```

---

## Circuit Breaker Pattern

```mermaid
stateDiagram-v2
    [*] --> Closed: Tool available

    Closed --> Closed: Execution succeeds<br/>Reset failure count
    Closed --> Open: 3 consecutive failures<br/>on same tool+args

    Open --> Open: Execution blocked<br/>Return helpful error

    note right of Closed
        Normal operation.
        Each failure increments counter.
        Success resets counter.
    end note

    note right of Open
        Tool+args combination blocked.
        Agent receives context about
        prior failures to try
        alternative approach.
    end note
```

---

## Data Flow: Task Lifecycle

```mermaid
sequenceDiagram
    participant User
    participant CLI as CLI / WebUI
    participant Agent
    participant Prompt
    participant LLM
    participant Tool
    participant Session as SessionManager
    participant Context as ContextManager

    User->>CLI: Submit task
    CLI->>Agent: run(task)

    loop Until task complete or max iterations
        Agent->>Context: manageContext(steps)
        Context-->>Agent: managed steps

        Agent->>Prompt: decideNextStep()
        Prompt-->>Agent: formatted prompt

        Agent->>LLM: functionCall(prompt, toolsSchema)
        LLM-->>Agent: {tool, args} or final_answer

        alt final_answer
            Agent->>Session: saveState(completed)
            Agent-->>CLI: Return answer
            CLI-->>User: Display result
        else tool call
            Agent->>Tool: execute(args)
            Tool-->>Agent: result / error
            Agent->>Session: saveState(running)
        end
    end
```
