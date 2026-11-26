<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Connect {{ $client->name }} · {{ config('app.name') }}</title>

    {{-- Plain CSS: there's no Filament theme or Tailwind build for pages outside the panel. --}}
    <style>
        :root {
            color-scheme: light dark;
            --background: #fafafa;
            --card: #ffffff;
            --border: #e4e4e7;
            --text: #18181b;
            --muted: #71717a;
            --primary: #b45309;
            --primary-hover: #92400e;
            --primary-text: #ffffff;
            --secondary-hover: #f4f4f5;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #09090b;
                --card: #18181b;
                --border: #3f3f46;
                --text: #fafafa;
                --muted: #a1a1aa;
                --primary: #f59e0b;
                --primary-hover: #fbbf24;
                --primary-text: #18181b;
                --secondary-hover: #27272a;
            }
        }
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: var(--background);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        main {
            width: 100%;
            max-width: 26rem;
            padding: 2rem 1.5rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
        }

        .brand {
            margin: 0 0 1.5rem;
            text-align: center;
            font-size: 1.25rem;
            font-weight: 700;
        }

        h1 {
            margin: 0 0 0.75rem;
            font-size: 1.25rem;
            font-weight: 600;
            text-align: center;
        }

        p {
            margin: 0 0 0.75rem;
        }

        .muted {
            color: var(--muted);
            font-size: 0.875rem;
            text-align: center;
            overflow-wrap: anywhere;
        }

        .actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        form {
            flex: 1;
            margin: 0;
        }

        button {
            width: 100%;
            min-height: 2.75rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .secondary {
            background: var(--card);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .secondary:hover {
            background: var(--secondary-hover);
        }

        .primary {
            background: var(--primary);
            color: var(--primary-text);
            border: 1px solid transparent;
        }

        .primary:hover {
            background: var(--primary-hover);
        }
    </style>
</head>
<body>
    <main>
        <p class="brand">{{ config('app.name') }}</p>

        <h1>Connect {{ $client->name }}?</h1>

        <p>{{ $client->name }} will be able to see your preferences, plans and what your scans found, change the pages you watch, and start searches, which cost money.</p>

        <p class="muted">Signed in as {{ $user->email }}</p>

        <p class="muted">You'll be sent to {{ $request->query('redirect_uri', $client->redirect_uris[0] ?? '') }}</p>

        <div class="actions">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="secondary">Cancel</button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="primary">Connect</button>
            </form>
        </div>
    </main>
</body>
</html>
