# Chat Mode Improvements Needed

## 1. Context Compression & Summarization

**Current Issue**: The ContextManager currently only does basic trimming and doesn't actually compress or summarize older context.

**Why It Matters**:

- As conversations get longer, we lose important early context
- Simply dropping old steps means the agent forgets what files it created, what the user asked for initially, etc.
- Token usage becomes inefficient without compression

**Proposed Solution**:

- Implement actual context compression using LLM to summarize groups of related steps
- Keep a "context digest" of important facts discovered
- Compress observations that are similar (e.g., multiple file reads)

## 2. Better Follow-up Recognition

**Current Issue**: The LLM-based follow-up detection adds latency and cost for every input.

**Why It Matters**:

- Every chat input now requires an extra LLM call just to classify it
- This doubles the API calls and adds 1-2 seconds of latency
- Simple follow-ups like "another one" shouldn't need classification

**Proposed Solution**:

- Hybrid approach: quick pattern matching for obvious follow-ups, LLM for ambiguous cases
- Cache common follow-up patterns
- Include conversation history in the agent's context directly

## 3. Tool Execution Loop Prevention

**Current Issue**: Agent can get stuck repeatedly executing the same tool (as seen with write_file being called 20 times).

**Why It Matters**:

- Wastes tokens and API calls
- Creates confusing output for users
- Can hit max iteration limits without completing the task

**Proposed Solution**:

- Implement better duplicate detection that considers tool + arguments + recent results
- Add a "circuit breaker" pattern for repeated failures
- Give the agent explicit feedback when it's repeating actions

## 4. Conversation Memory

**Current Issue**: Each task in chat mode is treated somewhat independently, losing conversational flow.

**Why It Matters**:

- Agent doesn't build on previous answers naturally
- User has to repeat context
- Doesn't feel like a continuous conversation

**Proposed Solution**:

- Maintain a "conversation summary" that persists across tasks
- Include key facts, user preferences, and topic continuity
- Add this to the agent's goal/context

## 5. Smarter Context Window Management

**Current Issue**: Fixed window size (15 steps) doesn't adapt to conversation needs.

**Why It Matters**:

- Some tasks need more context, others need less
- Long tool outputs take up valuable context space
- Important early context gets dropped

**Proposed Solution**:

- Dynamic context window based on available tokens
- Compress tool outputs more aggressively
- Keep "index" of important findings that can be recalled

## 6. Visual Output Improvements

**Current Issue**: Long outputs from tools (like browse_website) dominate the conversation visually.

**Why It Matters**:

- Hard to follow the conversation flow
- Important information gets buried in walls of text
- Chat mode should be more conversational

**Proposed Solution**:

- Truncate long observations in display (but keep full context internally)
- Add collapsible sections for verbose output
- Better visual hierarchy for conversation turns

## 7. Session Integration

**Current Issue**: Chat mode doesn't integrate well with session saving/resuming.

**Why It Matters**:

- Can't resume a chat conversation later
- Loses conversation history between sessions
- No way to "bookmark" interesting conversations

**Proposed Solution**:

- Auto-save chat sessions with meaningful IDs
- Allow resuming chat sessions with full context
- Export conversation history

## Implementation Priority

1. **High Priority**: Context compression (it's partially implemented but not working)
2. **High Priority**: Tool execution loop prevention (affects usability)
3. **Medium Priority**: Better conversation memory
4. **Medium Priority**: Smarter context window management
5. **Low Priority**: Visual improvements
6. **Low Priority**: Session integration improvements
7. **Low Priority**: Optimize follow-up recognition
