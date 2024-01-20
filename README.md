<p align="center"><img src="./art/header.png"></p>

# Agent - Library for building AI agents in PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/helgesverre/agent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/agent)
[![Total Downloads](https://img.shields.io/packagist/dt/helgesverre/agent.svg?style=flat-square)](https://packagist.org/packages/helgesverre/agent)

Proof of concept AI Agent using the [Brain](https://github.com/helgesverre/brain) package.

## See it in action.

<a href="https://share.cleanshot.com/kTZXyFnY"><img src="./art/thumb.png"></a>

Video Link: https://share.cleanshot.com/kTZXyFnY

## Installation

Install it via composer:

```bash
composer require helgesverre/agent
```

## TODO:

- Add more tests for the agent class
- Add database tool (sqlite)
- Trello tool
- Google Keep tool
- Google Calendar Tool
- trim the interaction history to a max length so we dont overflow the context length.
- Implement "crew" feature (multiple agents)
    - Delegate task to other agent
    - Ask other agent for help


- Vector database integration (swappable?)
    - https://turbopuffer.com/
    - Qdrant
    - Chroma
    - Milvus

## Ideas:

- Investigate embedding [Smol Developer](https://github.com/smol-ai/developer) as a tool
- Implement a "memory" feature, so the agent can remember
  things. [AgentMemory](https://github.com/autonomousresearchgroup/agentmemory)
- Implement an API that follows the [Agent Protocol](https://agentprotocol.ai/) (or make up something similar)
- Separate out the "agent" library code, then build a gui on top of it. (Electron, NativePHP, Livewire with regular web
  server?)
- Figure out if streaming is a pain in the ass to implement using generators
- Integrate [Aider](https://aider.chat) via Docker as a runnable
  tool [see](https://aider.chat/docs/faq.html#can-i-script-aider)
- Look into how to make a usable abstraction around https://github.com/chrome-php/chrome that the Agent can interact
  with, so you could make it browse a website and discover links , buttons etc without passing the entire html content
  into the prompt, then provide interaction with it via a seperate agent (hands of the subtask to the browser agent,
  which knows the original task and some context, then is only focused on performing those browsing steps in sequence.)

## Architecture Todos

- Extract agent library into seperate package
- Bundle reusable and generic tools as part of agent lib, things that are specifi to myself (trello, imap) should be
  moved into:
- Make standard laravel  (not laravel zero) webapp that will use the agent library, provide custom tools and a web ui.
    - setup Laravel wave and livewire event listening stuff
    - https://github.com/qruto/laravel-wave
    - https://fly.io/laravel-bytes/streaming-to-the-browser-with-livewire/

## Web UI

Structure:

3 column layout


---- 

- Crews
    - Agent 1 & 2
        - Task 1
        - Task 2
            - Timeline (Actions, observations, thoughts etc)
- Agents
    - Task 1
    - Task 2
        - Timeline (Actions, observations, thoughts etc)


- Create Crew
    - Create or select agent
        - Name
        - Goal
        - Tools
    - Create Tasks
        - Task Description
        - Task specific tools
- Create Agent

---- 
