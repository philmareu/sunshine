{{-- Shown while a search runs. Filament only polls a table that has rows, so this polls until results arrive. --}}
<div wire:poll.5s class="fi-ta-empty-state" role="status">
    <div class="fi-ta-empty-state-content">
        <div class="fi-ta-empty-state-icon-bg">
            <x-filament::loading-indicator class="h-6 w-6" />
        </div>

        <h3 class="fi-ta-empty-state-heading">
            {{ __('Searching…') }}
        </h3>

        <p class="fi-ta-empty-state-description">
            {{ __('This usually takes a minute or two. Results appear here when it\'s done.') }}
        </p>
    </div>
</div>
