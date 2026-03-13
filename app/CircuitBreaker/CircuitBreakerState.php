<?php

namespace App\CircuitBreaker;

/**
 * Represents the current state of a circuit breaker
 */
enum CircuitBreakerState: string
{
    case CLOSED = 'closed';     // Normal operation
    case OPEN = 'open';         // Blocking executions
    case HALF_OPEN = 'half_open'; // Testing recovery
}
