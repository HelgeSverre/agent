<?php

use App\Providers\AppServiceProvider;
use OpenAI\Laravel\ServiceProvider;
use Webklex\IMAP\Providers\LaravelServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => 'Agent',

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value determines the "version" your application is currently running
    | in. You may want to follow the "Semantic Versioning" - Given a version
    | number MAJOR.MINOR.PATCH when an update happens: https://semver.org.
    |
    */

    'version' => app('git.version'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. This can be overridden using
    | the global command line "--env" option when calling commands.
    |
    */

    'env' => 'development',

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => [
        AppServiceProvider::class,
        ServiceProvider::class,
        LaravelServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Parallel Execution Configuration
    |--------------------------------------------------------------------------
    |
    | Configure parallel tool execution behavior
    |
    */

    'parallel_execution' => [
        'enabled' => env('AGENT_PARALLEL_ENABLED', false),  // Opt-in by default
        'max_processes' => env('AGENT_MAX_PARALLEL', 4),
        'timeout' => env('AGENT_TOOL_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Compression Configuration
    |--------------------------------------------------------------------------
    |
    | Configure intelligent context compression behavior for conversation
    | history management and memory optimization.
    |
    */

    'context_compression' => [
        'enabled' => env('AGENT_COMPRESSION_ENABLED', true),

        'triggers' => [
            'token_threshold' => env('AGENT_COMPRESSION_TOKEN_THRESHOLD', 8000),
            'step_threshold' => env('AGENT_COMPRESSION_STEP_THRESHOLD', 25),
            'memory_threshold' => env('AGENT_COMPRESSION_MEMORY_THRESHOLD', 0.8),
            'time_threshold' => env('AGENT_COMPRESSION_TIME_THRESHOLD', 3600), // 1 hour
            'pattern_threshold' => env('AGENT_COMPRESSION_PATTERN_THRESHOLD', 3),
        ],

        'emergency_triggers' => [
            'token_threshold' => env('AGENT_EMERGENCY_TOKEN_THRESHOLD', 15000),
            'step_threshold' => env('AGENT_EMERGENCY_STEP_THRESHOLD', 50),
            'memory_threshold' => env('AGENT_EMERGENCY_MEMORY_THRESHOLD', 0.95),
        ],

        'preservation' => [
            'always_preserve' => [
                'file_operations',
                'user_preferences',
                'key_decisions',
                'error_solutions',
                'task_completions',
            ],
            'compress_detail' => [
                'intermediate_reasoning',
                'tool_execution_details',
                'exploration_steps',
            ],
            'aggressive_compress' => [
                'debug_information',
                'verbose_output',
                'repeated_failures',
                'non_actionable_observations',
            ],
        ],

        'llm' => [
            'model' => env('AGENT_COMPRESSION_MODEL', 'gpt-4o-mini'),
            'temperature' => env('AGENT_COMPRESSION_TEMPERATURE', 0.1),
            'max_tokens' => env('AGENT_COMPRESSION_MAX_TOKENS', 1000),
        ],

        'memory' => [
            'working_ttl' => env('AGENT_MEMORY_WORKING_TTL', 0), // Current session
            'compressed_ttl' => env('AGENT_MEMORY_COMPRESSED_TTL', 86400), // 24 hours
            'archive_ttl' => env('AGENT_MEMORY_ARCHIVE_TTL', 2592000), // 30 days
            'metadata_ttl' => env('AGENT_MEMORY_METADATA_TTL', 7776000), // 90 days
            'max_compressed_contexts' => env('AGENT_MAX_COMPRESSED_CONTEXTS', 100),
        ],

        'performance' => [
            'enable_monitoring' => env('AGENT_COMPRESSION_MONITORING', true),
            'compression_ratio_target' => env('AGENT_COMPRESSION_RATIO_TARGET', 0.7),
            'response_time_limit' => env('AGENT_COMPRESSION_TIME_LIMIT', 5.0),
            'cost_tracking_enabled' => env('AGENT_COMPRESSION_COST_TRACKING', true),
        ],

        'strategies' => [
            'simple_threshold' => env('AGENT_SIMPLE_COMPRESSION_THRESHOLD', 5),
            'intelligent_threshold' => env('AGENT_INTELLIGENT_COMPRESSION_THRESHOLD', 15),
            'pattern_detection_enabled' => env('AGENT_PATTERN_DETECTION_ENABLED', true),
            'boundary_detection_enabled' => env('AGENT_BOUNDARY_DETECTION_ENABLED', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Configure circuit breaker behavior for preventing tool execution loops
    |
    */

    'circuit_breaker' => [
        'enabled' => env('AGENT_CIRCUIT_BREAKER_ENABLED', true),

        'default' => [
            'failure_threshold' => env('CIRCUIT_BREAKER_FAILURE_THRESHOLD', 3),
            'duplicate_threshold' => env('CIRCUIT_BREAKER_DUPLICATE_THRESHOLD', 2),
            'recovery_timeout' => env('CIRCUIT_BREAKER_RECOVERY_TIMEOUT', 60),
            'max_half_open_attempts' => env('CIRCUIT_BREAKER_HALF_OPEN_ATTEMPTS', 3),
            'time_window' => env('CIRCUIT_BREAKER_TIME_WINDOW', 60),
        ],

        'similarity' => [
            'enabled' => env('CIRCUIT_BREAKER_SIMILARITY_ENABLED', true),
            'threshold' => env('CIRCUIT_BREAKER_SIMILARITY_THRESHOLD', 0.85),
            'string_threshold' => env('CIRCUIT_BREAKER_STRING_SIMILARITY', 0.8),
            'array_threshold' => env('CIRCUIT_BREAKER_ARRAY_SIMILARITY', 0.75),
            'levenshtein_enabled' => env('CIRCUIT_BREAKER_LEVENSHTEIN', true),
            'soundex_enabled' => env('CIRCUIT_BREAKER_SOUNDEX', false),
        ],

        'tool_overrides' => [
            'read_file' => [
                'duplicate_threshold' => 1,
                'similarity_threshold' => 0.95,
            ],
            'write_file' => [
                'duplicate_threshold' => 1,
                'similarity_threshold' => 0.95,
            ],
            'search_web' => [
                'duplicate_threshold' => 3,
                'time_window' => 30,
            ],
            'browse_website' => [
                'failure_threshold' => 5,
                'recovery_timeout' => 30,
            ],
        ],

        'patterns' => [
            'detect_loops' => env('CIRCUIT_BREAKER_DETECT_LOOPS', true),
            'detect_cycles' => env('CIRCUIT_BREAKER_DETECT_CYCLES', true),
            'detect_progressive' => env('CIRCUIT_BREAKER_DETECT_PROGRESSIVE', true),
            'min_pattern_length' => env('CIRCUIT_BREAKER_MIN_PATTERN_LENGTH', 2),
        ],

        'monitoring' => [
            'track_metrics' => env('CIRCUIT_BREAKER_TRACK_METRICS', true),
            'log_blocks' => env('CIRCUIT_BREAKER_LOG_BLOCKS', true),
            'alert_threshold' => env('CIRCUIT_BREAKER_ALERT_THRESHOLD', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Follow-up Recognition Configuration
    |--------------------------------------------------------------------------
    |
    | Configure hybrid follow-up recognition for chat mode efficiency
    |
    */

    'follow_up_recognition' => [
        'enabled' => env('AGENT_FOLLOW_UP_ENABLED', true),

        'pattern_matching' => [
            'enabled' => env('FOLLOW_UP_PATTERN_ENABLED', true),
            'cache_patterns' => env('FOLLOW_UP_CACHE_PATTERNS', true),
            'debug_mode' => env('FOLLOW_UP_DEBUG_MODE', false),
        ],

        'confidence_thresholds' => [
            'high' => env('FOLLOW_UP_HIGH_CONFIDENCE', 0.9),
            'medium' => env('FOLLOW_UP_MEDIUM_CONFIDENCE', 0.7),
            'low' => env('FOLLOW_UP_LOW_CONFIDENCE', 0.5),
        ],

        'context' => [
            'max_history' => env('FOLLOW_UP_MAX_HISTORY', 5),
            'entity_tracking' => env('FOLLOW_UP_ENTITY_TRACKING', true),
            'topic_tracking' => env('FOLLOW_UP_TOPIC_TRACKING', true),
            'action_tracking' => env('FOLLOW_UP_ACTION_TRACKING', true),
        ],

        'llm_fallback' => [
            'enabled' => env('FOLLOW_UP_LLM_FALLBACK', true),
            'model' => env('FOLLOW_UP_LLM_MODEL', 'gpt-3.5-turbo'),
            'temperature' => env('FOLLOW_UP_LLM_TEMPERATURE', 0.2),
            'max_tokens' => env('FOLLOW_UP_LLM_TOKENS', 150),
        ],

        'performance' => [
            'track_metrics' => env('FOLLOW_UP_TRACK_METRICS', true),
            'target_response_time' => env('FOLLOW_UP_TARGET_TIME', 100), // ms
            'cache_results' => env('FOLLOW_UP_CACHE_RESULTS', true),
            'cache_ttl' => env('FOLLOW_UP_CACHE_TTL', 300), // 5 minutes
        ],
    ],

];
