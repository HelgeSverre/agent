# Enhanced Context Compression System Architecture

## 1. Overview

The enhanced context compression system for the PHP Agent framework provides intelligent LLM-based summarization that preserves critical information while managing memory efficiently. This system integrates seamlessly with the Agent class to automatically compress conversation history when thresholds are reached.

## 2. System Architecture

### 2.1 Core Components

```
┌─────────────────────────────────────────────────────────────┐
│                    Agent Framework                          │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────┐    ┌──────────────┐    ┌───────────────┐  │
│  │   Agent     │────│ContextManager│────│ContextCompressor│  │
│  │             │    │              │    │               │  │
│  └─────────────┘    └──────────────┘    └───────────────┘  │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────────┐   ┌──────────────┐   ┌──────────────┐   │
│  │CompressionTri│   │MemoryManager │   │ConfigManager │   │
│  │ggerService   │   │              │   │              │   │
│  └──────────────┘   └──────────────┘   └──────────────┘   │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────────┐   ┌──────────────┐   ┌──────────────┐   │
│  │PerformanceMo│   │SessionStore  │   │ContextCache │   │
│  │nitorService  │   │              │   │              │   │
│  └──────────────┘   └──────────────┘   └──────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Information Flow

```
User Input → Agent → Context Check → Compression Trigger → LLM Summarization → Memory Storage
     ↑                                      ↓
     └── Context Retrieved ← Memory Manager ←
```

## 3. Compression Triggering Strategy

### 3.1 Trigger Conditions

- **Size-based**: When context exceeds configurable token/step thresholds
- **Time-based**: Periodic compression of old context (configurable intervals)
- **Pattern-based**: When repetitive patterns are detected
- **Memory-based**: When memory usage exceeds limits
- **Session-based**: At natural conversation boundaries

### 3.2 Trigger Decision Matrix

| Condition          | Threshold  | Action                         |
| ------------------ | ---------- | ------------------------------ |
| Token count        | > 8000     | Immediate compression          |
| Step count         | > 25       | Compress oldest segments       |
| Memory usage       | > 80%      | Aggressive compression         |
| Pattern repetition | > 3 cycles | Compress repeated patterns     |
| Session boundary   | New task   | Compress previous task context |

## 4. Information Preservation Strategy

### 4.1 Critical Information Categories

**Always Preserve:**

- File operations (created, modified, deleted files)
- User preferences and settings
- Key decisions and their rationales
- Error patterns and solutions
- Important tool results
- Task completion status

**Compress with Detail:**

- Intermediate reasoning steps
- Tool execution details (keep results, compress process)
- Exploratory actions
- Redundant observations

**Aggressive Compression:**

- Debug information
- Verbose tool outputs
- Repeated failed attempts
- Non-actionable observations

### 4.2 Preservation Rules

```php
Priority Levels:
1. CRITICAL (100): File ops, user prefs, decisions, errors
2. IMPORTANT (75): Tool results, task status, key findings
3. USEFUL (50): Reasoning, context, intermediate steps
4. VERBOSE (25): Debug info, exploratory actions
5. REDUNDANT (10): Repeated patterns, failed attempts
```

## 5. Memory Management Strategy

### 5.1 Multi-Tier Storage

```
┌─────────────────────────────────────────┐
│           Working Memory                │
│     (Current conversation context)      │
├─────────────────────────────────────────┤
│           Compressed Memory             │
│      (Recent compressed contexts)       │
├─────────────────────────────────────────┤
│           Archive Memory                │
│     (Long-term compressed storage)      │
├─────────────────────────────────────────┤
│           Metadata Store                │
│    (Indexes, tags, search vectors)      │
└─────────────────────────────────────────┘
```

### 5.2 Memory Lifecycle

1. **Working Memory** (TTL: Current session)
    - Raw conversation steps
    - Real-time compression candidates

2. **Compressed Memory** (TTL: 24 hours)
    - LLM-compressed summaries
    - Key information extracts
    - Cross-references to detailed data

3. **Archive Memory** (TTL: 30 days)
    - Highly compressed historical data
    - Pattern libraries
    - Learning outcomes

4. **Metadata Store** (TTL: 90 days)
    - Search indexes
    - Context tags
    - Usage statistics

## 6. Integration Points with Agent Class

### 6.1 Hook Points

```php
Agent Lifecycle Hooks:
- beforeIteration(): Check compression triggers
- afterToolExecution(): Update context scores
- beforeDecision(): Ensure context fits limits
- afterTaskCompletion(): Compress task context
- sessionRestore(): Load compressed context
```

### 6.2 Context Management Flow

```
Agent.run()
    ↓
trimIntermediateSteps()
    ↓
ContextManager.manageContext()
    ↓
CompressionTriggerService.shouldCompress()
    ↓
ContextCompressor.compressWithLLM()
    ↓
MemoryManager.store()
```

## 7. Configuration System

### 7.1 Configuration Structure

```php
'context_compression' => [
    'enabled' => true,
    'triggers' => [
        'token_threshold' => 8000,
        'step_threshold' => 25,
        'memory_threshold' => 0.8,
        'time_threshold' => 3600, // seconds
    ],
    'preservation' => [
        'always_preserve' => ['file_operations', 'user_preferences', 'decisions'],
        'compress_detail' => ['reasoning', 'tool_results'],
        'aggressive_compress' => ['debug_info', 'verbose_output'],
    ],
    'llm' => [
        'model' => 'gpt-4o-mini',
        'temperature' => 0.1,
        'max_tokens' => 1000,
    ],
    'memory' => [
        'working_ttl' => 0, // Current session
        'compressed_ttl' => 86400, // 24 hours
        'archive_ttl' => 2592000, // 30 days
        'metadata_ttl' => 7776000, // 90 days
    ],
    'performance' => [
        'enable_monitoring' => true,
        'compression_ratio_target' => 0.7,
        'response_time_limit' => 5.0,
    ],
],
```

## 8. Performance Monitoring

### 8.1 Metrics to Track

- Compression ratio (original size vs compressed size)
- Information preservation accuracy
- Compression latency
- Memory usage reduction
- Context retrieval speed
- LLM API costs

### 8.2 Monitoring Dashboard

```
Compression Performance:
├── Compression Ratio: 73% (target: 70%)
├── Avg Compression Time: 2.3s (limit: 5s)
├── Memory Saved: 45MB (last 24h)
├── Information Loss Rate: 3% (target: <5%)
└── API Cost: $12.45 (monthly budget: $100)
```

## 9. Error Handling & Recovery

### 9.1 Fallback Strategies

1. **LLM Compression Failure**: Fall back to rule-based compression
2. **Memory Storage Failure**: Use local file cache
3. **Context Corruption**: Rebuild from session data
4. **Performance Degradation**: Reduce compression frequency

### 9.2 Data Integrity

- Checksum validation for stored contexts
- Version tracking for compression algorithms
- Rollback capability for failed compressions
- Audit logging for all compression operations

## 10. Implementation Phases

### Phase 1: Core Enhancement

- Enhanced ContextCompressor with effective LLM integration
- Configuration system implementation
- Basic memory management

### Phase 2: Advanced Features

- Multi-tier memory storage
- Performance monitoring
- Pattern-based triggers

### Phase 3: Optimization

- Machine learning for compression decisions
- Distributed compression for high-volume scenarios
- Advanced context retrieval with vector search

## 11. Security Considerations

- **Data Privacy**: Ensure sensitive information is properly handled during compression
- **API Security**: Secure LLM API calls with proper authentication
- **Storage Security**: Encrypt stored compressed contexts
- **Access Control**: Implement proper permissions for context access

## 12. Testing Strategy

- Unit tests for compression algorithms
- Integration tests for Agent class integration
- Performance benchmarks for compression efficiency
- Load testing for memory management
- A/B testing for information preservation quality
