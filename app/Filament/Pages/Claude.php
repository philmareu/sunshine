<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Connects Claude Code to Sunshine's MCP server (routes/ai.php). Each user has one token; making a new one ends the
 * old. Sanctum keeps only its hash, so the token is shown once, until the page is left.
 */
class Claude extends Page
{
    public const TOKEN_NAME = 'mcp';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $navigationLabel = 'Claude';

    protected static ?string $title = 'Connect Claude';

    protected static ?int $navigationSort = 8;

    public ?string $token = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newToken')
                ->label(fn (): string => $this->currentToken() ? __('Replace token') : __('Create token'))
                ->icon(Heroicon::OutlinedKey)
                // Only replacing a token needs a confirmation; the first one is made straight away.
                ->modal(fn (): bool => $this->currentToken() !== null)
                ->requiresConfirmation()
                ->modalHeading(__('Replace your token?'))
                ->modalDescription(__('The current token stops working, so Claude Code needs the new one.'))
                ->modalSubmitActionLabel(__('Replace'))
                ->action(function (): void {
                    $this->user()->tokens()->where('name', self::TOKEN_NAME)->delete();
                    $this->token = $this->user()->createToken(self::TOKEN_NAME)->plainTextToken;
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Your new token'))
                    ->description(__('Shown only this once. Run this in a terminal to add Sunshine to Claude Code everywhere, then start a new session.'))
                    ->visible(fn (): bool => $this->token !== null)
                    ->schema([
                        TextEntry::make('command')
                            ->hiddenLabel()
                            ->state(fn (): string => sprintf(
                                'claude mcp add --transport http --scope user sunshine %s --header "Authorization: Bearer %s"',
                                url('/mcp'),
                                $this->token,
                            ))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                    ]),
                Section::make(__('MCP server'))
                    ->description(__('Claude can read your preferences, check, add and remove the pages you watch, and start and read ad hoc searches, as you.'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('url')
                            ->label(__('Server URL'))
                            ->state(url('/mcp'))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('tokenStatus')
                            ->label(__('Your token'))
                            ->state(fn (): string => match ($token = $this->currentToken()) {
                                null => __('None yet'),
                                default => __('Made :made, :used', [
                                    'made' => $token->created_at->diffForHumans(),
                                    'used' => $token->last_used_at ? __('last used :when', ['when' => $token->last_used_at->diffForHumans()]) : __('not used yet'),
                                ]),
                            }),
                    ]),
            ]);
    }

    protected function currentToken(): ?PersonalAccessToken
    {
        return $this->user()->tokens()->where('name', self::TOKEN_NAME)->latest()->first();
    }

    protected function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
