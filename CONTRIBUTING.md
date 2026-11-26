# Contributing

Sunshine is a personal project, shared as-is. Please read this before you spend time on an issue or pull request.

## What to expect

- Issues and pull requests may go unanswered, for a long time or for good.
- Nothing is guaranteed to be merged, however good it is.
- Small, focused pull requests with tests are the only ones with a realistic chance.
- For anything bigger, open an issue first to ask whether it's wanted. That issue may not be answered either, so don't start big work counting on it.
- Forking is encouraged. If you want Sunshine to do something it doesn't, a fork is the dependable way to get it.

## The approach

Sunshine is a deliberately lean build: features are added one at a time, as real use shows the need. A change that adds options, settings or features "in case" is unlikely to be taken. A change that fixes a real problem, simply, is the best fit.

## Running the tests and the formatter

```bash
php artisan test --compact
vendor/bin/pint
```

Tests never reach the web or the Anthropic API: the agents are faked with `EventScout::fake()`, `PageReader::fake()` and `TasteProfiler::fake()`, and the browser with `Process::fake()`. New tests should do the same.

## Pull requests

- One change per pull request.
- Add or update tests for what you changed, and make sure the suite passes.
- Run Pint before you push.
- Say what the change fixes or adds and why.
