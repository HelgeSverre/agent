# Architecture Decision Records (ADRs)

## ADR-001: Hybrid Recognition Strategy over Pure Pattern Matching

### Status

**ACCEPTED** - 2024

### Context

The current `enhanceTaskWithContext` method in RunAgent.php uses LLM classification for every user input, causing performance issues:

- 100% API overhead for all inputs
- 1-2 second latency for simple cases like "yes", "fix it"
- Doubled API costs
- Poor user experience in chat mode

We considered three approaches:

1. **Pure Pattern Matching**: Fast but limited accuracy
2. **Pure LLM Classification**: Accurate but expensive and slow
3. **Hybrid Approach**: Combines both for optimal balance

### Decision

We will implement a **hybrid recognition strategy** that processes inputs through three sequential stages:

1. **Fast Pattern Matching** (70-80% of cases, ~1ms)
2. **Context Analysis** (15-20% of cases, ~50ms)
3. **LLM Fallback** (5-10% of cases, ~1000ms)

### Consequences

**Positive:**

- **94% performance improvement**: Average response time drops from ~1000ms to ~61ms
- **75% API call reduction**: Most cases handled without LLM
- **Better user experience**: Immediate responses for common inputs
- **Cost savings**: Significant reduction in API costs
- **Scalability**: System can handle more concurrent users

**Negative:**

- **Implementation complexity**: More code and logic to maintain
- **Accuracy risks**: Pattern matching may miss edge cases
- **Testing overhead**: Need comprehensive test coverage for all paths
- **Debugging complexity**: Multiple failure modes to consider

**Risks & Mitigations:**

- **Risk**: Pattern matching false positives
- **Mitigation**: Conservative confidence thresholds and LLM fallback
- **Risk**: Accuracy regression
- **Mitigation**: Comprehensive A/B testing and monitoring

---

## ADR-002: Three-Tier Processing Architecture

### Status

**ACCEPTED** - 2024

### Context

We needed to decide how to structure the recognition pipeline to optimize for both performance and accuracy. Options considered:

1. **Two-tier**: Pattern → LLM fallback
2. **Three-tier**: Pattern → Context → LLM fallback
3. **Four-tier**: Multiple intermediate stages

### Decision

We will implement a **three-tier architecture**:

**Tier 1 - Pattern Matching (Fast Path)**

- Target: <1ms processing time
- Coverage: 70-80% of inputs
- Method: Regex-based pattern matching
- Confidence: High (0.85-0.95)

**Tier 2 - Context Analysis (Medium Path)**

- Target: <50ms processing time
- Coverage: 15-20% of inputs
- Method: Topic similarity, entity tracking, action continuity
- Confidence: Medium (0.60-0.85)

**Tier 3 - LLM Classification (Slow Path)**

- Target: <1000ms processing time
- Coverage: 5-10% of inputs
- Method: Optimized LLM prompt
- Confidence: High (0.80-0.90)

### Consequences

**Positive:**

- **Granular optimization**: Each tier optimized for its use case
- **Performance predictability**: Clear performance characteristics
- **Accuracy layering**: Multiple chances to classify correctly
- **Monitoring clarity**: Easy to track performance by tier

**Negative:**

- **Code complexity**: Three separate processing paths
- **Integration challenges**: Coordination between tiers
- **Performance tuning**: Need to optimize three different systems

---

## ADR-003: Minimal Context Storage Strategy

### Status

**ACCEPTED** - 2024

### Context

Context management is critical for follow-up recognition but must balance information richness with memory efficiency and processing speed. Options considered:

1. **Full Context**: Store complete conversation history
2. **Rolling Window**: Fixed-size sliding window
3. **Minimal Context**: Store only essential information with compression

### Decision

We will implement a **minimal context storage strategy**:

**Context Window Management:**

- Maximum 10 recent exchanges stored
- Automatic compression when limit exceeded
- Preserve key entities and actions across compressions

**Information Stored:**

- **Exchange Data**: User input, agent response, timestamp
- **Entity Tracking**: Files, URLs, class names, technical terms
- **Action History**: Last significant action performed
- **Topic Continuity**: Key topics from recent conversation

**Compression Strategy:**

- Summarize older exchanges when window full
- Maintain entity relevance scoring
- Preserve action continuity chain

### Consequences

**Positive:**

- **Memory efficiency**: Bounded memory usage (~20KB overhead)
- **Fast processing**: Quick context lookups
- **Scalability**: Constant memory usage regardless of conversation length
- **Essential preservation**: Key information retained through compression

**Negative:**

- **Information loss**: Older context may be lost
- **Compression complexity**: Need intelligent summarization
- **Tuning required**: Window size and compression thresholds need optimization

---

## ADR-004: Pattern Rule Definition Approach

### Status

**ACCEPTED** - 2024

### Context

Pattern matching rules need to be maintainable, extensible, and performant. We considered:

1. **Hardcoded Rules**: Rules embedded in code
2. **Configuration Files**: External YAML/JSON configuration
3. **Database Storage**: Dynamic rule management
4. **PHP Class Definition**: Structured class-based approach

### Decision

We will implement **PHP class-based pattern definitions** with confidence tiers:

**Structure:**

```php
class PatternRules {
    public function getHighConfidencePatterns(): array
    public function getMediumConfidencePatterns(): array
    public function getLowConfidencePatterns(): array
}
```

**Pattern Categories:**

- **High Confidence (0.90-0.95)**: Definitive patterns like "fix it", "yes", "another one"
- **Medium Confidence (0.75-0.89)**: Contextual patterns like "what about", "similar"
- **Low Confidence (0.60-0.74)**: General patterns like standalone pronouns

### Consequences

**Positive:**

- **Type safety**: PHP type checking and IDE support
- **Performance**: No file I/O or parsing overhead
- **Version control**: Rules tracked with code changes
- **Testing**: Easy to unit test pattern definitions

**Negative:**

- **Deployment required**: Rule changes need code deployment
- **Less dynamic**: Cannot modify rules without code changes
- **Developer dependency**: Non-developers cannot modify rules

---

## ADR-005: LLM Optimization Strategy

### Status

**ACCEPTED** - 2024

### Context

LLM fallback needs optimization to minimize cost and latency while maintaining accuracy. Current implementation uses full conversation context and verbose prompts.

### Decision

We will implement **aggressive LLM optimization**:

**Prompt Optimization:**

- Reduce prompt size by 80% (from ~500 to ~100 tokens)
- Use structured JSON response format
- Remove conversational elements

**Context Reduction:**

- Use only last 2-3 exchanges instead of full history
- Truncate long responses to 200 characters
- Remove redundant information

**Caching Strategy:**

- Cache LLM responses for similar inputs
- Use MD5 hash of input + context snippet as cache key
- Implement LRU eviction for cache size management

**Model Selection:**

- Use faster, cheaper models (GPT-3.5) for simple cases
- Reserve premium models for complex edge cases

### Consequences

**Positive:**

- **Cost reduction**: 60-70% reduction in token usage
- **Latency improvement**: Faster API responses
- **Cache efficiency**: Reduced repeated API calls
- **Resource optimization**: Better resource utilization

**Negative:**

- **Accuracy risk**: Reduced context may impact quality
- **Cache complexity**: Additional caching layer to maintain
- **Model management**: Need logic to select appropriate model

---

## ADR-006: Error Handling and Fallback Strategy

### Status

**ACCEPTED** - 2024

### Context

The hybrid system introduces multiple failure points that need graceful handling to ensure system reliability.

### Decision

We will implement **graceful degradation** with multiple fallback levels:

**Failure Handling Hierarchy:**

1. **Pattern Matching Failure** → Proceed to Context Analysis
2. **Context Analysis Failure** → Proceed to LLM Classification
3. **LLM API Failure** → Return original input unchanged
4. **Complete System Failure** → Log error and continue with original input

**Error Scenarios:**

- **Network issues**: Timeout handling for LLM calls
- **Invalid responses**: JSON parsing errors, malformed responses
- **Resource exhaustion**: Memory limits, cache failures
- **Configuration errors**: Missing pattern rules, invalid settings

**Monitoring Requirements:**

- Track failure rates for each component
- Alert on high failure rates or performance degradation
- Log detailed error information for debugging

### Consequences

**Positive:**

- **System reliability**: No single point of failure
- **User experience**: System continues working even with component failures
- **Debuggability**: Clear error tracking and logging
- **Maintenance**: Failures don't break the entire chat system

**Negative:**

- **Complexity**: Multiple error handling paths
- **Testing overhead**: Need to test all failure scenarios
- **Performance impact**: Error handling adds minor overhead

---

## ADR-007: Performance Monitoring and Alerting

### Status

**ACCEPTED** - 2024

### Context

The hybrid system requires comprehensive monitoring to ensure performance targets are met and to detect regressions early.

### Decision

We will implement **comprehensive performance monitoring**:

**Metrics Tracked:**

- Processing time per recognition method
- Method usage distribution (pattern/context/llm percentages)
- Cache hit rates and effectiveness
- Error rates and failure patterns
- Memory usage and resource consumption

**Performance Targets:**

- Pattern matching: <1ms for 95% of cases
- Context analysis: <50ms for 95% of cases
- LLM classification: <1000ms for 95% of cases
- Overall average: <100ms (75% improvement target)

**Alerting Thresholds:**

- Pattern matching >2ms consistently
- Context analysis >100ms consistently
- LLM usage >15% of total requests
- Error rate >5% for any component

**Reporting:**

- Real-time performance dashboard
- Daily performance summary reports
- Weekly trend analysis and recommendations

### Consequences

**Positive:**

- **Proactive issue detection**: Early warning of performance problems
- **Optimization guidance**: Data-driven optimization decisions
- **SLA compliance**: Ensure performance targets are met
- **Capacity planning**: Understanding of system resource usage

**Negative:**

- **Monitoring overhead**: Additional system resources required
- **Alert fatigue**: Need to tune thresholds to avoid noise
- **Maintenance burden**: Monitoring system needs maintenance

---

## ADR-008: Integration Approach with RunAgent.php

### Status

**ACCEPTED** - 2024

### Context

Integration with existing RunAgent.php chat mode must maintain backward compatibility while enabling the new hybrid recognition system.

### Decision

We will implement **non-breaking incremental integration**:

**Integration Strategy:**

1. **Phase 1**: Replace `enhanceTaskWithContext()` method with hybrid recognizer
2. **Phase 2**: Add conversation context management to `runChatMode()`
3. **Phase 3**: Add optional performance monitoring and metrics

**Backward Compatibility:**

- Maintain existing method signatures
- Fall back to original LLM approach if hybrid system fails
- Preserve existing chat mode behavior and user interface
- Keep all existing command-line options and flags

**Configuration:**

- Add optional configuration for enabling/disabling hybrid recognition
- Allow tuning of performance thresholds and cache sizes
- Provide verbose mode for debugging and monitoring

**Migration Path:**

- Default to hybrid recognition for new installations
- Provide flag to disable hybrid recognition if issues occur
- Gradual rollout with A/B testing capability

### Consequences

**Positive:**

- **Zero downtime**: Can be deployed without service interruption
- **Risk mitigation**: Easy rollback if issues discovered
- **User experience**: No changes to user interface or commands
- **Testing**: Can be thoroughly tested in production environment

**Negative:**

- **Code duplication**: Temporary duplication during transition
- **Configuration complexity**: Additional configuration options
- **Migration effort**: Need careful testing and rollout planning

---

## Summary of Architecture Decisions

| ADR     | Decision                    | Impact                            | Status   |
| ------- | --------------------------- | --------------------------------- | -------- |
| ADR-001 | Hybrid Recognition Strategy | 94% performance improvement       | ACCEPTED |
| ADR-002 | Three-Tier Architecture     | Clear performance characteristics | ACCEPTED |
| ADR-003 | Minimal Context Storage     | Bounded memory usage              | ACCEPTED |
| ADR-004 | PHP Class Pattern Rules     | Type safety and performance       | ACCEPTED |
| ADR-005 | LLM Optimization            | 60-70% cost reduction             | ACCEPTED |
| ADR-006 | Graceful Degradation        | System reliability                | ACCEPTED |
| ADR-007 | Performance Monitoring      | Proactive issue detection         | ACCEPTED |
| ADR-008 | Incremental Integration     | Zero downtime deployment          | ACCEPTED |

## Implementation Priority

Based on these ADRs, the recommended implementation priority is:

### High Priority (Week 1)

- ADR-001: Hybrid Recognition Strategy
- ADR-002: Three-Tier Architecture
- ADR-004: Pattern Rule Definitions
- ADR-008: Basic Integration

### Medium Priority (Week 2)

- ADR-003: Context Management
- ADR-005: LLM Optimization
- ADR-006: Error Handling

### Low Priority (Week 3)

- ADR-007: Performance Monitoring
- Performance tuning and optimization
- Documentation and training

This architectural foundation provides a solid basis for implementing the hybrid follow-up recognition system with clear decision rationale and implementation guidance.
